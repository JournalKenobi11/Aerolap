<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity\Running;

use App\Domain\Activity\Running\RunningEffortScore;
use App\Domain\Athlete\HeartRateZone\TimeInHeartRateZones;
use PHPUnit\Framework\TestCase;

final class RunningEffortScoreTest extends TestCase
{
    public function testEasyRecoveryRunScore(): void
    {
        // 30 mins all in Zone 1 (1800s)
        $zones = TimeInHeartRateZones::create(1800, 0, 0, 0, 0);
        $effort = RunningEffortScore::fromHeartRateZones($zones, 1800);

        // 30 min * 1.0 * 0.25 = 7.5 -> 8
        $this->assertSame(8, $effort->getScore());
        $this->assertSame('Light / Recovery', $effort->getLabel());
        $this->assertSame(12, $effort->getEstimatedRecoveryHours());
    }

    public function testModerateAerobicRunScore(): void
    {
        // 60 mins in Zone 2 (3600s)
        $zones = TimeInHeartRateZones::create(300, 3300, 0, 0, 0);
        $effort = RunningEffortScore::fromHeartRateZones($zones, 3600);

        // (5 * 1.0 + 55 * 2.0) * 0.25 = (5 + 110) * 0.25 = 28.75 -> 29
        $this->assertSame(29, $effort->getScore());
        $this->assertSame('Moderate Aerobic', $effort->getLabel());
        $this->assertSame(24, $effort->getEstimatedRecoveryHours());
    }

    public function testHardTempoRunScore(): void
    {
        // 60 mins: 10m Z1, 20m Z3, 25m Z4, 5m Z5
        $zones = TimeInHeartRateZones::create(600, 0, 1200, 1500, 300);
        $effort = RunningEffortScore::fromHeartRateZones($zones, 3600);

        // (10*1 + 20*4 + 25*7 + 5*11) * 0.25 = (10 + 80 + 175 + 55) * 0.25 = 320 * 0.25 = 80
        $this->assertSame(80, $effort->getScore());
        $this->assertSame('Hard / Sustained', $effort->getLabel());
        $this->assertSame(36, $effort->getEstimatedRecoveryHours());
    }

    public function testNullZonesFallback(): void
    {
        $effort = RunningEffortScore::fromHeartRateZones(null, 3600);
        $this->assertSame(30, $effort->getScore());
    }
}
