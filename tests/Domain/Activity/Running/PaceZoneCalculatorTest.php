<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity\Running;

use App\Domain\Activity\Running\PaceZoneCalculator;
use App\Domain\Athlete\PaceZone\PaceZone;
use App\Domain\Athlete\PaceZone\PaceZones;
use PHPUnit\Framework\TestCase;

final class PaceZoneCalculatorTest extends TestCase
{
    private PaceZoneCalculator $calculator;
    private PaceZones $zones;

    protected function setUp(): void
    {
        $this->calculator = new PaceZoneCalculator();
        $this->zones = PaceZones::default();
    }

    public function testEmptyStreamsReturnEmptyResult(): void
    {
        $result = $this->calculator->calculate(
            paceZones: $this->zones,
            timeStream: [],
            velocityStream: [],
        );

        $this->assertSame(0, $result->getTotalMovingTimeInSeconds());
        $this->assertSame(0, $result->getExcludedPausedSeconds());
    }

    public function testSinglePointReturnsEmptyResult(): void
    {
        $result = $this->calculator->calculate(
            paceZones: $this->zones,
            timeStream: [0],
            velocityStream: [3.0],
        );

        $this->assertSame(0, $result->getTotalMovingTimeInSeconds());
    }

    public function testTimeWeightingWithUnequalIntervals(): void
    {
        // 5:00/km = 300 sec/km -> speed = 1000/300 = 3.333 m/s (Zone 3: 300-345 s/km, speed 2.90-3.33 m/s)
        // 3.2 m/s -> pace = 312.5 s/km -> Zone 3
        // 4.0 m/s -> pace = 250 s/km -> Zone 5 (< 270 s/km)
        $timeStream = [0, 5, 10, 20]; // intervals: 5s, 5s, 10s
        $velocityStream = [3.2, 3.2, 4.0, 4.0]; // 5s in Z3, 5s in Z3, 10s in Z5

        $result = $this->calculator->calculate(
            paceZones: $this->zones,
            timeStream: $timeStream,
            velocityStream: $velocityStream,
        );

        $this->assertSame(10, $result->getTimeInZone(PaceZone::THREE));
        $this->assertSame(10, $result->getTimeInZone(PaceZone::FIVE));
        $this->assertSame(20, $result->getTotalMovingTimeInSeconds());
        $this->assertEquals(50.0, $result->getPercentageInZone(PaceZone::THREE));
        $this->assertEquals(50.0, $result->getPercentageInZone(PaceZone::FIVE));
    }

    public function testStationaryAndPausedPeriodsAreExcluded(): void
    {
        $timeStream = [0, 10, 20, 30]; // three 10-second intervals
        $velocityStream = [0.1, 3.2, 0.0, 0.0]; // 10s stopped (< 0.5), 10s moving (Z3), 10s stopped

        $result = $this->calculator->calculate(
            paceZones: $this->zones,
            timeStream: $timeStream,
            velocityStream: $velocityStream,
        );

        $this->assertSame(10, $result->getTimeInZone(PaceZone::THREE));
        $this->assertSame(10, $result->getTotalMovingTimeInSeconds());
        $this->assertSame(20, $result->getExcludedPausedSeconds());
        $this->assertEquals(100.0, $result->getPercentageInZone(PaceZone::THREE));
    }

    public function testLongRecordingGapsAreExcluded(): void
    {
        $timeStream = [0, 10, 100, 110]; // interval 1: 10s, interval 2: 90s gap (>60s), interval 3: 10s
        $velocityStream = [3.2, 3.2, 3.2, 3.2];

        $result = $this->calculator->calculate(
            paceZones: $this->zones,
            timeStream: $timeStream,
            velocityStream: $velocityStream,
        );

        // 10s + 10s = 20s moving
        $this->assertSame(20, $result->getTotalMovingTimeInSeconds());
        // 90s gap excluded
        $this->assertSame(90, $result->getExcludedPausedSeconds());
    }
}
