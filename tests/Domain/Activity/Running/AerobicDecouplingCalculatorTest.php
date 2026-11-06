<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity\Running;

use App\Domain\Activity\Running\AerobicDecouplingCalculator;
use PHPUnit\Framework\TestCase;

final class AerobicDecouplingCalculatorTest extends TestCase
{
    private AerobicDecouplingCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new AerobicDecouplingCalculator();
    }

    public function testShortActivityIsIneligible(): void
    {
        // 10 minutes run (600s)
        $time = range(0, 600, 5);
        $velocity = array_fill(0, count($time), 3.0);
        $hr = array_fill(0, count($time), 140);

        $result = $this->calculator->calculate(
            timeStream: $time,
            velocityStream: $velocity,
            heartRateStream: $hr,
        );

        $this->assertFalse($result->isEligible());
        $this->assertStringContainsString('too short', (string) $result->getIneligibilityReason());
    }

    public function testMissingHeartRateIsIneligible(): void
    {
        // 30 minutes run (1800s) but no HR data
        $time = range(0, 1800, 5);
        $velocity = array_fill(0, count($time), 3.0);
        $hr = array_fill(0, count($time), null);

        $result = $this->calculator->calculate(
            timeStream: $time,
            velocityStream: $velocity,
            heartRateStream: $hr,
        );

        $this->assertFalse($result->isEligible());
        $this->assertStringContainsString('missing', (string) $result->getIneligibilityReason());
    }

    public function testIntervalWorkoutWithHighPaceVariabilityIsIneligible(): void
    {
        // 30 minutes alternating between sprinting 5.5 m/s and walking 1.0 m/s
        $time = [];
        $velocity = [];
        $hr = [];
        for ($t = 0; $t <= 1800; $t += 10) {
            $time[] = $t;
            $velocity[] = (intdiv($t, 60) % 2 === 0) ? 5.5 : 1.2;
            $hr[] = 160;
        }

        $result = $this->calculator->calculate(
            timeStream: $time,
            velocityStream: $velocity,
            heartRateStream: $hr,
        );

        $this->assertFalse($result->isEligible());
        $this->assertStringContainsString('variability', (string) $result->getIneligibilityReason());
    }

    public function testSteadyRunDecouplingCalculation(): void
    {
        // 40 minutes steady run (2400 seconds)
        // Constant speed 3.0 m/s (5:33 /km)
        // First half (after 10 min warmup): HR = 140 bpm
        // Second half: HR drifts up to 150 bpm (cardiac drift)
        $time = [];
        $velocity = [];
        $hr = [];

        for ($t = 0; $t <= 2400; $t += 2) {
            $time[] = $t;
            $velocity[] = 3.0; // 3.0 m/s steady
            // Half 1 steady is roughly 600s - 1450s, Half 2 is 1450s - 2300s
            $hr[] = ($t < 1450) ? 140 : 150;
        }

        $result = $this->calculator->calculate(
            timeStream: $time,
            velocityStream: $velocity,
            heartRateStream: $hr,
        );

        $this->assertTrue($result->isEligible());
        $this->assertNull($result->getIneligibilityReason());
        $this->assertEquals(140, $result->getHalfOneAvgHeartRate());
        $this->assertEquals(150, $result->getHalfTwoAvgHeartRate());
        $this->assertEqualsWithDelta(10.0, $result->getHeartRateDriftBpm(), 0.5);

        // EF1 = 3.0 / 140 = 0.021428
        // EF2 = 3.0 / 150 = 0.020000
        // Decoupling % = ((0.021428 - 0.02) / 0.021428) * 100 = ~ 6.7%
        $this->assertGreaterThan(5.0, $result->getDecouplingPercentage());
        $this->assertLessThan(8.0, $result->getDecouplingPercentage());
        $this->assertStringContainsString('Moderate Decoupling', $result->getAssessment());
    }

    public function testZeroDecouplingWithCompletelyStableHeartRate(): void
    {
        // 35 minutes steady run with identical HR throughout
        $time = [];
        $velocity = [];
        $hr = [];

        for ($t = 0; $t <= 2100; $t += 2) {
            $time[] = $t;
            $velocity[] = 3.2;
            $hr[] = 145;
        }

        $result = $this->calculator->calculate(
            timeStream: $time,
            velocityStream: $velocity,
            heartRateStream: $hr,
        );

        $this->assertTrue($result->isEligible());
        $this->assertEquals(0.0, $result->getDecouplingPercentage());
        $this->assertEquals(0.0, $result->getHeartRateDriftBpm());
        $this->assertStringContainsString('Excellent Aerobic Base', $result->getAssessment());
    }
}
