<?php

declare(strict_types=1);

namespace App\Domain\Gear\AddGear;

use App\Domain\Activity\SportType\SportTypes;
use App\Domain\Gear\Gear;
use App\Domain\Gear\GearId;
use App\Domain\Gear\GearRepository;
use App\Domain\Gear\GearType;
use App\Domain\Gear\Maintenance\GearComponent;
use App\Domain\Gear\Maintenance\GearComponentId;
use App\Domain\Gear\Maintenance\GearComponentRepository;
use App\Domain\Gear\Maintenance\Installation\GearComponentInstallation;
use App\Domain\Gear\Maintenance\Installation\GearComponentInstallations;
use App\Domain\Gear\Maintenance\Installation\InstallationParent;
use App\Domain\Image\ImageDirectory;
use App\Domain\Image\ImageStorage;
use App\Domain\Image\NewImage;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\String\Name;
use Money\Money;

final readonly class AddGearCommandHandler implements CommandHandler
{
    public function __construct(
        private GearRepository $gearRepository,
        private Clock $clock,
        private ImageStorage $imageStorage,
        private GearComponentRepository $gearComponentRepository,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof AddGear);

        $gear = Gear::create(
            gearId: GearId::random(),
            createdOn: $this->clock->getCurrentDateTimeImmutable(),
            name: $command->getName(),
            isRetired: $command->isRetired(),
            type: GearType::CUSTOM,
        );

        if ($command->getPurchasePrice() instanceof Money) {
            $gear = $gear->withPurchasePrice($command->getPurchasePrice());
        }

        $newImage = $command->getNewImage();
        if ($newImage instanceof NewImage) {
            $gear = $gear->withLocalImagePath(
                $this->imageStorage->store(
                    newImage: $newImage,
                    directory: ImageDirectory::GEAR
                )->toLocalImagePath()
            );
        }

        $this->gearRepository->add($gear);

        if ($command->getMaintenanceTasks() !== null && !$command->getMaintenanceTasks()->isEmpty()) {
            $backingComponent = GearComponent::create(
                id: GearComponentId::random(),
                label: Name::fromString($gear->getName()),
                installations: GearComponentInstallations::fromArray([
                    GearComponentInstallation::create(
                        parent: InstallationParent::gear($gear->getId()),
                        from: null,
                        until: null,
                        sportTypes: SportTypes::empty(),
                    ),
                ]),
                localImagePath: $gear->getLocalImagePath(),
                purchasePrice: null,
                isRetired: $gear->isRetired(),
                notes: null,
            );
            foreach ($command->getMaintenanceTasks() as $task) {
                $backingComponent->addMaintenanceTask($task);
            }
            $this->gearComponentRepository->add($backingComponent);
        }
    }
}
