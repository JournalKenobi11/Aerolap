<?php

declare(strict_types=1);

namespace App\Domain\Gear\UpdateGear;

use App\Domain\Activity\SportType\SportTypes;
use App\Domain\Gear\GearRepository;
use App\Domain\Gear\Maintenance\GearComponent;
use App\Domain\Gear\Maintenance\GearComponentId;
use App\Domain\Gear\Maintenance\GearComponentRepository;
use App\Domain\Gear\Maintenance\Installation\GearComponentInstallation;
use App\Domain\Gear\Maintenance\Installation\GearComponentInstallations;
use App\Domain\Gear\Maintenance\Installation\InstallationParent;
use App\Domain\Image\ImageDirectory;
use App\Domain\Image\ImageStorage;
use App\Domain\Image\NewImage;
use App\Domain\Image\RemovedImage;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\ValueObject\String\Name;
use Money\Money;

final readonly class UpdateGearCommandHandler implements CommandHandler
{
    public function __construct(
        private GearRepository $gearRepository,
        private ImageStorage $imageStorage,
        private GearComponentRepository $gearComponentRepository,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof UpdateGear);

        $gear = $this->gearRepository->find($command->getGearId())
            ->withName($command->getName())
            ->withIsRetired($command->isRetired());

        if ($command->getPurchasePrice() instanceof Money) {
            $gear = $gear->withPurchasePrice($command->getPurchasePrice());
        }

        $newImage = $command->getNewImage();
        $removedImage = $command->getRemovedImage();

        if ($newImage instanceof NewImage) {
            $gear = $gear->withLocalImagePath(
                $this->imageStorage->store(
                    newImage: $newImage,
                    directory: ImageDirectory::GEAR
                )->toLocalImagePath()
            );
        } elseif ($removedImage instanceof RemovedImage) {
            $gear = $gear->withLocalImagePath(null);
        }

        $this->gearRepository->update($gear);

        if ($removedImage instanceof RemovedImage) {
            $this->imageStorage->remove($removedImage->getPath());
        }

        if (null !== $command->getMaintenanceTasks()) {
            $gearComponents = $this->gearComponentRepository->findAll();
            $backingComponent = null;

            foreach ($gearComponents as $component) {
                $installations = $component->getInstallations();
                if (1 === count($installations) && (string) $installations->getFirst()?->getParent()->getGearId() === (string) $gear->getId()) {
                    $backingComponent = $component;
                    break;
                }
            }

            if ($backingComponent instanceof GearComponent) {
                $backingComponent = $backingComponent
                    ->withLabel(Name::fromString($gear->getName()))
                    ->withIsRetired($gear->isRetired())
                    ->withLocalImagePath($gear->getLocalImagePath())
                    ->withMaintenanceTasks($command->getMaintenanceTasks());
                $this->gearComponentRepository->update($backingComponent);
            } elseif (!$command->getMaintenanceTasks()->isEmpty()) {
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
}
