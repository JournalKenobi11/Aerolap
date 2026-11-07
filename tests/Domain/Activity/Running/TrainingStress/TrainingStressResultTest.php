<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity\Running\TrainingStress;

use App\Domain\Activity\Running\TrainingStress\TrainingStressResult;
use PHPUnit\Framework\TestCase;

final class TrainingStressResultTest extends TestCase
{
    public function testResultToArrayMapping(): void
    {
        $badge = [
            'code' => 'steady',
            'label' => 'Steady Progression',
            'color' => 'green',
            'description' => 'Volume and cardio load are within recommended bands.',
        ];

        $result = new TrainingStressResult(
            configVersion: '1.0.0',
            statusBadge: $badge,
            isInsufficientHistory: false,
            isTapering: false,
            isPostRace: false,
            hasSorenessOverride: false,
            hasSorenessCaution: false,
            hasConsecutiveSorenessAlert: false,
            subjectiveLog: ['date' => '2026-11-07', 'sorenessScore' => 0, 'location' => 'None'],
            acuteRunKm: 17.1,
            acuteWalkKm: 8.4,
            acuteHikeKm: 0.0,
            acuteEffectiveKm: 19.2,
            acuteRunCount: 3,
            chronicBaseRunKm: 20.4,
            chronicBaseEffectiveKm: 22.5,
            volumeStepKm: -3.3,
            allowedStepKm: 4.54,
            volumeStepStatus: 'Safe',
            longestRunKm: 8.5,
            prior30dMaxLongKm: 8.0,
            longRunStepKm: 0.5,
            allowedLongRunStepKm: 1.2,
            longRunStatus: 'Safe',
            ctl: 11.7,
            atl: 23.0,
            tsb: -11.3,
            tsbPercentile: 3.3,
            isTsbLow: true,
            athleteMaxHr: 194,
            athleteRestingHr: 55,
            hrCalcMethod: 'Heart Rate Reserve (HRR)',
            targetRaceDate: '2026-11-22',
            daysToRace: 14,
            guidanceHeadline: 'Steady Progression',
            guidanceText: 'Training stress is balanced.',
            historyWeeks: [],
            chartData: [],
        );

        $array = $result->toArray();

        $this->assertSame('1.0.0', $array['configVersion']);
        $this->assertSame('steady', $array['statusBadge']['code']);
        $this->assertSame(17.1, $array['acuteRunKm']);
        $this->assertSame(19.2, $array['acuteEffectiveKm']);
        $this->assertSame(-11.3, $array['tsb']);
        $this->assertSame(14, $array['daysToRace']);
    }
}
