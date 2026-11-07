<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity\Stream;

use App\Domain\Athlete\HeartRateZone\TimeInHeartRateZones;
use PHPUnit\Framework\TestCase;

final class MonthlyHeartRateZonesTest extends TestCase
{
    public function testTimeInHeartRateZonesPercentages(): void
    {
        $zones = TimeInHeartRateZones::create(
            timeInZoneOne: 1200,   // 20 min
            timeInZoneTwo: 1800,   // 30 min
            timeInZoneThree: 2400, // 40 min
            timeInZoneFour: 600,   // 10 min
            timeInZoneFive: 0,     // 0 min
        );

        $this->assertSame(6000, $zones->getTotalTimeInSeconds());
        $this->assertSame(20.0, $zones->getPercentageInZoneOne());
        $this->assertSame(30.0, $zones->getPercentageInZoneTwo());
        $this->assertSame(40.0, $zones->getPercentageInZoneThree());
        $this->assertSame(10.0, $zones->getPercentageInZoneFour());
        $this->assertSame(0.0, $zones->getPercentageInZoneFive());
    }

    public function testEmptyZonesPercentages(): void
    {
        $zones = TimeInHeartRateZones::create(0, 0, 0, 0, 0);

        $this->assertSame(0, $zones->getTotalTimeInSeconds());
        $this->assertSame(0.0, $zones->getPercentageInZoneOne());
        $this->assertSame(0.0, $zones->getPercentageInZoneTwo());
        $this->assertSame(0.0, $zones->getPercentageInZoneThree());
        $this->assertSame(0.0, $zones->getPercentageInZoneFour());
        $this->assertSame(0.0, $zones->getPercentageInZoneFive());
    }
}
