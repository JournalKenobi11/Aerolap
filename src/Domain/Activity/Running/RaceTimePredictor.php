<?php

declare(strict_types=1);

namespace App\Domain\Activity\Running;

final readonly class RaceTimePredictor
{
    public const float DEFAULT_FATIGUE_EXPONENT = 1.06;

    public const array STANDARD_TARGETS = [
        ['distance' => 5000, 'label' => '5K'],
        ['distance' => 10000, 'label' => '10K'],
        ['distance' => 21097, 'label' => 'Half Marathon'],
        ['distance' => 42195, 'label' => 'Marathon'],
    ];

    /**
     * @param float $fatigueExponent Pete Riegel fatigue factor k (standard: 1.06)
     */
    public function __construct(
        private float $fatigueExponent = self::DEFAULT_FATIGUE_EXPONENT,
    ) {
    }

    /**
     * Predict race time using Pete Riegel formula:
     * T2 = T1 * (D2 / D1)^k
     */
    public function predictTime(
        int $sourceTimeSeconds,
        int $sourceDistanceMeters,
        int $targetDistanceMeters,
        ?float $exponent = null,
    ): int {
        if ($sourceDistanceMeters <= 0 || $sourceTimeSeconds <= 0 || $targetDistanceMeters <= 0) {
            return 0;
        }

        $k = $exponent ?? $this->fatigueExponent;
        $ratio = (float) $targetDistanceMeters / (float) $sourceDistanceMeters;
        $predictedTime = (float) $sourceTimeSeconds * ($ratio ** $k);

        return (int) round($predictedTime);
    }

    /**
     * @param list<array{
     *     activityId: string,
     *     activityName: string,
     *     date: string,
     *     distanceMeters: int,
     *     timeSeconds: int,
     *     daysAgo?: int
     * }> $performances
     *
     * @return RacePrediction[] Keyed or indexed array of predictions for standard distances
     */
    public function predictAll(array $performances, ?string $referenceDate = null): array
    {
        if ([] === $performances) {
            return [];
        }

        $refTimestamp = null !== $referenceDate ? strtotime($referenceDate) : time();
        $enrichedPerformances = [];

        foreach ($performances as $perf) {
            if ($perf['distanceMeters'] < 800 || $perf['timeSeconds'] <= 0) {
                continue; // Ignore extremely short efforts
            }

            $perfTime = strtotime($perf['date']);
            $daysAgo = $perf['daysAgo'] ?? (int) max(0, round(($refTimestamp - $perfTime) / 86400));

            $enrichedPerformances[] = array_merge($perf, ['daysAgo' => $daysAgo]);
        }

        if ([] === $enrichedPerformances) {
            return [];
        }

        $predictions = [];
        foreach (self::STANDARD_TARGETS as $target) {
            $pred = $this->predictForTarget($target['distance'], $target['label'], $enrichedPerformances);
            if ($pred instanceof RacePrediction) {
                $predictions[] = $pred;
            }
        }

        return $predictions;
    }

    /**
     * @param list<array{
     *     activityId: string,
     *     activityName: string,
     *     date: string,
     *     distanceMeters: int,
     *     timeSeconds: int,
     *     daysAgo: int
     * }> $performances
     */
    public function predictForTarget(int $targetDistance, string $targetLabel, array $performances): ?RacePrediction
    {
        // 1. Filter candidates to recent performances (prefer <= 90 days, fallback <= 180 days, then all)
        $candidates = array_filter($performances, static fn (array $p): bool => $p['daysAgo'] <= 90);
        if ([] === $candidates) {
            $candidates = array_filter($performances, static fn (array $p): bool => $p['daysAgo'] <= 180);
        }
        if ([] === $candidates) {
            $candidates = $performances;
        }

        // 2. Score candidates to find the most representative effort
        // We evaluate Riegel prediction and penalize extreme distance distance differences
        $bestCandidate = null;
        $bestScore = PHP_FLOAT_MAX;
        $bestPredictedSeconds = 0;

        foreach ($candidates as $candidate) {
            $predictedSeconds = $this->predictTime(
                sourceTimeSeconds: $candidate['timeSeconds'],
                sourceDistanceMeters: $candidate['distanceMeters'],
                targetDistanceMeters: $targetDistance
            );

            // Distance similarity ratio (log scale penalty for extrapolating too far)
            $distRatio = (float) $candidate['distanceMeters'] / (float) $targetDistance;
            $distPenalty = abs(log($distRatio));

            // Small recency penalty (1% per 30 days)
            $recencyPenalty = ($candidate['daysAgo'] / 30.0) * 0.05;

            // Score: predicted pace * penalty weight
            // We want the fastest predicted time among appropriate distances
            $score = $predictedSeconds * (1.0 + ($distPenalty * 0.25) + $recencyPenalty);

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestCandidate = $candidate;
                $bestPredictedSeconds = $predictedSeconds;
            }
        }

        if (null === $bestCandidate || $bestPredictedSeconds <= 0) {
            return null;
        }

        // 3. Determine confidence & uncertainty range
        $sourceDist = $bestCandidate['distanceMeters'];
        $daysAgo = $bestCandidate['daysAgo'];
        $ratio = (float) $sourceDist / (float) $targetDistance;

        if ($ratio >= 0.50 && $daysAgo <= 60) {
            $confidence = 'High';
            $uncertainty = 0.03; // +/- 3%
            $notes = 'High confidence based on a recent race-distance or near-distance effort.';
        } elseif ($ratio >= 0.25 && $daysAgo <= 120) {
            $confidence = 'Moderate';
            $uncertainty = 0.06; // +/- 6%
            $notes = 'Moderate confidence. Derived from a shorter training effort; endurance pacing required.';
        } else {
            $confidence = 'Low';
            $uncertainty = 0.10; // +/- 10%
            $notes = sprintf('Low confidence. Extrapolated from a %s effort. Actual finish depends heavily on weekly volume and long run readiness.', sprintf('%.1f km', $sourceDist / 1000.0));
        }

        $lowerBound = (int) round($bestPredictedSeconds * (1.0 - $uncertainty));
        $upperBound = (int) round($bestPredictedSeconds * (1.0 + $uncertainty));

        return new RacePrediction(
            targetDistanceMeters: $targetDistance,
            targetLabel: $targetLabel,
            predictedTimeSeconds: $bestPredictedSeconds,
            lowerBoundSeconds: $lowerBound,
            upperBoundSeconds: $upperBound,
            confidenceLevel: $confidence,
            sourceActivityId: $bestCandidate['activityId'],
            sourceActivityName: $bestCandidate['activityName'],
            sourceDate: $bestCandidate['date'],
            sourceDistanceMeters: $bestCandidate['distanceMeters'],
            sourceTimeSeconds: $bestCandidate['timeSeconds'],
            fatigueExponent: $this->fatigueExponent,
            notes: $notes,
        );
    }
}
