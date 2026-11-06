<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity\Running;

use App\Domain\Activity\Running\RaceTimePredictor;
use PHPUnit\Framework\TestCase;

final class RaceTimePredictorTest extends TestCase
{
    private RaceTimePredictor $predictor;

    protected function setUp(): void
    {
        $this->predictor = new RaceTimePredictor(1.06);
    }

    public function testRiegelFormulaDirectPrediction(): void
    {
        // 5000m in 1200 seconds (20:00, 4:00/km)
        // 10000m predicted = 1200 * (10000 / 5000)^1.06 = 1200 * 2^1.06 = 1200 * 2.08496 = 2501.95s (~41:42)
        $predicted10k = $this->predictor->predictTime(
            sourceTimeSeconds: 1200,
            sourceDistanceMeters: 5000,
            targetDistanceMeters: 10000,
        );

        $this->assertEqualsWithDelta(2502, $predicted10k, 2);
    }

    public function testPredictAllStandardTargets(): void
    {
        $performances = [
            [
                'activityId' => 'act-1',
                'activityName' => 'Fast 5K Race',
                'date' => '2026-10-01',
                'distanceMeters' => 5000,
                'timeSeconds' => 1320, // 22:00
                'daysAgo' => 15,
            ],
            [
                'activityId' => 'act-2',
                'activityName' => '10K Weekend Tempo',
                'date' => '2026-09-15',
                'distanceMeters' => 10000,
                'timeSeconds' => 2820, // 47:00
                'daysAgo' => 30,
            ],
        ];

        $predictions = $this->predictor->predictAll($performances, '2026-10-16');

        $this->assertCount(4, $predictions);

        // Check 5K prediction
        $p5k = $predictions[0];
        $this->assertSame('5K', $p5k->getTargetLabel());
        $this->assertSame('High', $p5k->getConfidenceLevel());
        $this->assertSame('Fast 5K Race', $p5k->getSourceActivityName());

        // Check 10K prediction
        $p10k = $predictions[1];
        $this->assertSame('10K', $p10k->getTargetLabel());
        $this->assertSame('High', $p10k->getConfidenceLevel());

        // Check Half Marathon prediction
        $pHalf = $predictions[2];
        $this->assertSame('Half Marathon', $pHalf->getTargetLabel());
        $this->assertGreaterThan($p10k->getPredictedTimeSeconds(), $pHalf->getPredictedTimeSeconds());

        // Check Marathon prediction
        $pMarathon = $predictions[3];
        $this->assertSame('Marathon', $pMarathon->getTargetLabel());
        $this->assertSame('Low', $pMarathon->getConfidenceLevel()); // 10k is < 25% of 42.2k
        $this->assertStringContainsString('Low confidence', (string) $pMarathon->getNotes());
    }

    public function testEmptyPerformancesReturnsEmpty(): void
    {
        $this->assertEmpty($this->predictor->predictAll([]));
    }
}
