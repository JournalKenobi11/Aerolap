<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity\Running;

use App\Domain\Activity\Running\RunningTrainingStatusAdvisor;
use PHPUnit\Framework\TestCase;

final class RunningTrainingStatusAdvisorTest extends TestCase
{
    public function testPeakingStatus(): void
    {
        // High CTL (45), Low ATL (20), High positive TSB (+25), CTL stable
        $advisor = RunningTrainingStatusAdvisor::analyze(
            ctl: 45.0,
            atl: 20.0,
            tsb: 25.0,
            acRatio: 0.44,
            ctlChangeLast7Days: -0.5
        );

        $this->assertSame('Peaking', $advisor->getStatus());
        $this->assertStringContainsString('Peak Performance', $advisor->getHeadline());
        $this->assertStringContainsString('optimal race readiness', $advisor->getPlainLanguageExplanation());
    }

    public function testOverreachingStatus(): void
    {
        // ACWR > 1.45 or TSB < -28
        $advisor = RunningTrainingStatusAdvisor::analyze(
            ctl: 30.0,
            atl: 55.0,
            tsb: -25.0,
            acRatio: 1.83,
            ctlChangeLast7Days: 4.0
        );

        $this->assertSame('Overreaching', $advisor->getStatus());
        $this->assertStringContainsString('Injury Risk', $advisor->getHeadline());
        $this->assertStringContainsString('rest day', strtolower($advisor->getNextWorkoutGuidance()));
    }

    public function testProductiveStatus(): void
    {
        // Healthy progression: ACWR in 0.85-1.35, CTL increasing
        $advisor = RunningTrainingStatusAdvisor::analyze(
            ctl: 38.0,
            atl: 42.0,
            tsb: -4.0,
            acRatio: 1.10,
            ctlChangeLast7Days: 1.8
        );

        $this->assertSame('Productive', $advisor->getStatus());
        $this->assertStringContainsString('progressive tempo', $advisor->getNextWorkoutGuidance());
    }

    public function testDetrainingStatus(): void
    {
        // High TSB, CTL dropping
        $advisor = RunningTrainingStatusAdvisor::analyze(
            ctl: 22.0,
            atl: 5.0,
            tsb: 24.0,
            acRatio: 0.23,
            ctlChangeLast7Days: -3.0
        );

        $this->assertSame('Detraining', $advisor->getStatus());
        $this->assertStringContainsString('Fitness Declining', $advisor->getHeadline());
    }
}
