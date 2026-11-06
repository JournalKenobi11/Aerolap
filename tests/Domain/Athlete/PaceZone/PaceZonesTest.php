<?php

declare(strict_types=1);

namespace App\Tests\Domain\Athlete\PaceZone;

use App\Domain\Athlete\PaceZone\PaceZone;
use App\Domain\Athlete\PaceZone\PaceZones;
use App\Infrastructure\Measurement\UnitSystem;
use PHPUnit\Framework\TestCase;

final class PaceZonesTest extends TestCase
{
    public function testDefaultZonesStructure(): void
    {
        $zones = PaceZones::default();
        $this->assertCount(5, $zones->getZones());

        $z1 = $zones->getZoneOne();
        $this->assertSame(PaceZone::ONE, $z1->getName());
        $this->assertSame(390, $z1->getMinPaceInSecPerKm());
        $this->assertNull($z1->getMaxPaceInSecPerKm());
        $this->assertSame('> 6:30 /km', $z1->getFormattedRange(UnitSystem::METRIC));

        $z5 = $zones->getZoneFive();
        $this->assertSame(PaceZone::FIVE, $z5->getName());
        $this->assertNull($z5->getMinPaceInSecPerKm());
        $this->assertSame(270, $z5->getMaxPaceInSecPerKm());
        $this->assertSame('< 4:30 /km', $z5->getFormattedRange(UnitSystem::METRIC));
    }

    public function testMatchingSpeedToZone(): void
    {
        $zones = PaceZones::default();

        // 0.2 m/s -> stationary -> null
        $this->assertNull($zones->matchSpeed(0.2));

        // 2.0 m/s -> 500 s/km (8:20/km) -> Zone 1 (> 6:30/km)
        $this->assertSame(PaceZone::ONE, $zones->matchSpeed(2.0)?->getName());

        // 2.7 m/s -> 370.4 s/km (6:10/km) -> Zone 2 (5:45 - 6:30 /km, 345 - 390 s)
        $this->assertSame(PaceZone::TWO, $zones->matchSpeed(2.7)?->getName());

        // 3.1 m/s -> 322.6 s/km (5:22/km) -> Zone 3 (5:00 - 5:45 /km, 300 - 345 s)
        $this->assertSame(PaceZone::THREE, $zones->matchSpeed(3.1)?->getName());

        // 3.5 m/s -> 285.7 s/km (4:45/km) -> Zone 4 (4:30 - 5:00 /km, 270 - 300 s)
        $this->assertSame(PaceZone::FOUR, $zones->matchSpeed(3.5)?->getName());

        // 4.2 m/s -> 238 s/km (3:58/km) -> Zone 5 (< 4:30/km, < 270 s)
        $this->assertSame(PaceZone::FIVE, $zones->matchSpeed(4.2)?->getName());
    }

    public function testFromThresholdPaceCalculatesCorrectBoundaries(): void
    {
        // 300 s/km (5:00 /km)
        $zones = PaceZones::fromThresholdPace(300);

        // Z1: > 129% = > 387 s/km
        $this->assertSame(387, $zones->getZoneOne()->getMinPaceInSecPerKm());
        // Z2: 114% - 129% = 342 - 387 s/km
        $this->assertSame(342, $zones->getZoneTwo()->getMinPaceInSecPerKm());
        $this->assertSame(387, $zones->getZoneTwo()->getMaxPaceInSecPerKm());
        // Z4: 98% - 105% = 294 - 318 s/km
        $this->assertSame(294, $zones->getZoneFour()->getMinPaceInSecPerKm());
        // Z5: < 97% = < 294 s/km
        $this->assertSame(294, $zones->getZoneFive()->getMaxPaceInSecPerKm());
    }
}
