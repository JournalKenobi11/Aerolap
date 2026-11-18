<?php

declare(strict_types=1);

namespace App\Domain\Gear\Maintenance;

use App\Domain\Gear\Maintenance\Task\MaintenanceTaskId;
use App\Infrastructure\Eventing\EventBus;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Repository\DbalRepository;
use App\Infrastructure\Serialization\Json;
use Doctrine\DBAL\Connection;

final readonly class DbalGearComponentRepository extends DbalRepository implements GearComponentRepository
{
    public function __construct(
        Connection $connection,
        private EventBus $eventBus,
    ) {
        parent::__construct($connection);
    }

    public function add(GearComponent $gearComponent): void
    {
        $sql = 'INSERT INTO GearComponent (gearComponentId, label, localImagePath, purchasePriceAmount, purchasePriceCurrency, isRetired, notes, installations, maintenanceTasks)
                VALUES (:gearComponentId, :label, :localImagePath, :purchasePriceAmount, :purchasePriceCurrency, :isRetired, :notes, :installations, :maintenanceTasks)';

        $this->connection->executeStatement($sql, [
            'gearComponentId' => (string) $gearComponent->getId(),
            'label' => (string) $gearComponent->getLabel(),
            'localImagePath' => $gearComponent->getLocalImagePath(),
            'purchasePriceAmount' => $gearComponent->getPurchasePrice()?->getAmount(),
            'purchasePriceCurrency' => $gearComponent->getPurchasePrice()?->getCurrency()->getCode(),
            'isRetired' => (int) $gearComponent->isRetired(),
            'notes' => $gearComponent->getNotes(),
            'installations' => Json::encode($gearComponent->getInstallations()),
            'maintenanceTasks' => Json::encode($gearComponent->getMaintenanceTasks()),
        ]);
        $this->eventBus->publishEvents($gearComponent->getRecordedEvents());
    }

    public function update(GearComponent $gearComponent): void
    {
        $sql = 'UPDATE GearComponent SET
                    label = :label,
                    localImagePath = :localImagePath,
                    purchasePriceAmount = :purchasePriceAmount,
                    purchasePriceCurrency = :purchasePriceCurrency,
                    isRetired = :isRetired,
                    notes = :notes,
                    installations = :installations,
                    maintenanceTasks = :maintenanceTasks
                WHERE gearComponentId = :gearComponentId';

        $this->connection->executeStatement($sql, [
            'gearComponentId' => (string) $gearComponent->getId(),
            'label' => (string) $gearComponent->getLabel(),
            'localImagePath' => $gearComponent->getLocalImagePath(),
            'purchasePriceAmount' => $gearComponent->getPurchasePrice()?->getAmount(),
            'purchasePriceCurrency' => $gearComponent->getPurchasePrice()?->getCurrency()->getCode(),
            'isRetired' => (int) $gearComponent->isRetired(),
            'notes' => $gearComponent->getNotes(),
            'installations' => Json::encode($gearComponent->getInstallations()),
            'maintenanceTasks' => Json::encode($gearComponent->getMaintenanceTasks()),
        ]);
        $this->eventBus->publishEvents($gearComponent->getRecordedEvents());
    }

    public function delete(GearComponent $gearComponent): void
    {
        $this->connection->executeStatement(
            'DELETE FROM GearComponent WHERE gearComponentId = :gearComponentId',
            ['gearComponentId' => (string) $gearComponent->getId()],
        );
        $this->eventBus->publishEvents($gearComponent->getRecordedEvents());
    }

    public function find(GearComponentId $gearComponentId): GearComponent
    {
        $result = $this->connection->executeQuery(
            'SELECT * FROM GearComponent WHERE gearComponentId = :gearComponentId',
            ['gearComponentId' => (string) $gearComponentId],
        )->fetchAssociative();

        if (false === $result) {
            throw new EntityNotFound(sprintf('GearComponent "%s" not found', $gearComponentId));
        }

        return $this->hydrate($result);
    }

    public function findAll(): GearComponents
    {
        $this->ensureComponentsForGears();

        return GearComponents::fromArray(array_map(
            $this->hydrate(...),
            $this->connection->executeQuery('SELECT * FROM GearComponent ORDER BY label ASC')->fetchAllAssociative(),
        ));
    }

    private function ensureComponentsForGears(): void
    {
        try {
            $gears = $this->connection->executeQuery('SELECT gearId, name, isRetired, localImagePath FROM Gear')->fetchAllAssociative();
            if ([] === $gears) {
                return;
            }

            $existingComponents = $this->connection->executeQuery('SELECT gearComponentId, label, isRetired, localImagePath, installations FROM GearComponent')->fetchAllAssociative();
            $coveredGears = [];
            foreach ($existingComponents as $componentRow) {
                $installations = Json::decode($componentRow['installations'] ?? '[]');
                foreach ($installations as $inst) {
                    if (isset($inst['parent']) && str_starts_with((string) $inst['parent'], 'gear:')) {
                        $coveredGears[substr((string) $inst['parent'], 5)] = $componentRow;
                    }
                }
            }

            foreach ($gears as $gear) {
                $gearId = (string) $gear['gearId'];
                if (isset($coveredGears[$gearId])) {
                    $row = $coveredGears[$gearId];
                    if ($row['label'] !== $gear['name'] || (int) $row['isRetired'] !== (int) $gear['isRetired'] || $row['localImagePath'] !== $gear['localImagePath']) {
                        $this->connection->executeStatement(
                            'UPDATE GearComponent SET label = :label, isRetired = :isRetired, localImagePath = :localImagePath WHERE gearComponentId = :gearComponentId',
                            [
                                'label' => $gear['name'],
                                'isRetired' => (int) $gear['isRetired'],
                                'localImagePath' => $gear['localImagePath'],
                                'gearComponentId' => $row['gearComponentId'],
                            ]
                        );
                    }
                    continue;
                }

                $componentId = (string) GearComponentId::random();
                $tasks = [];

                $installations = [
                    [
                        'parent' => 'gear:' . $gearId,
                        'from' => null,
                        'until' => null,
                        'sportTypes' => [],
                    ],
                ];

                $sql = 'INSERT INTO GearComponent (gearComponentId, label, localImagePath, purchasePriceAmount, purchasePriceCurrency, isRetired, notes, installations, maintenanceTasks)
                        VALUES (:gearComponentId, :label, :localImagePath, NULL, NULL, :isRetired, NULL, :installations, :maintenanceTasks)';

                $this->connection->executeStatement($sql, [
                    'gearComponentId' => $componentId,
                    'label' => $gear['name'],
                    'localImagePath' => $gear['localImagePath'],
                    'isRetired' => (int) $gear['isRetired'],
                    'installations' => Json::encode($installations),
                    'maintenanceTasks' => Json::encode($tasks),
                ]);
            }
        } catch (\Throwable) {
            // Failsafe in case Gear table does not exist or schema is in flux
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function hydrate(array $result): GearComponent
    {
        return GearComponent::fromArray([
            'id' => $result['gearComponentId'],
            'label' => $result['label'],
            'localImagePath' => $result['localImagePath'],
            'purchasePrice' => null === $result['purchasePriceAmount'] ? null : [
                'amountInCents' => (int) $result['purchasePriceAmount'],
                'currency' => $result['purchasePriceCurrency'],
            ],
            'isRetired' => (bool) $result['isRetired'],
            'notes' => $result['notes'],
            'installations' => Json::decode($result['installations']),
            'maintenance' => Json::decode($result['maintenanceTasks']),
        ]);
    }
}
