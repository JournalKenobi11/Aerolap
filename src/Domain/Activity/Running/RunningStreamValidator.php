<?php

declare(strict_types=1);

namespace App\Domain\Activity\Running;

use App\Domain\Import\FileParser\StreamMath;

final readonly class RunningStreamValidator
{
    private const float MAX_RUNNING_SPEED_MPS = 12.0; // 43.2 km/h - world sprint record pace
    private const int MIN_CREDIBLE_HR = 45;
    private const int MAX_CREDIBLE_HR = 225;

    /**
     * @param list<int> $timeStream
     * @param list<?float> $velocityStream
     * @param list<?int>|null $heartRateStream
     * @param list<?float>|null $distanceStream
     * @param list<?float>|null $altitudeStream
     * @param list<?int>|null $cadenceStream
     *
     * @return array{
     *     score: int,
     *     rating: string,
     *     badgeColor: string,
     *     validRatio: float,
     *     pausedPercent: float,
     *     hrCoveragePercent: float,
     *     warnings: list<string>,
     *     summary: string
     * }
     */
    public function validate(
        array $timeStream,
        array $velocityStream,
        ?array $heartRateStream = null,
        ?array $distanceStream = null,
        ?array $altitudeStream = null,
        ?array $cadenceStream = null,
    ): array {
        $count = min(count($timeStream), count($velocityStream));
        if ($count < 2) {
            return [
                'score' => 0,
                'rating' => 'Empty / Invalid',
                'badgeColor' => 'text-red-700 bg-red-50 border-red-300',
                'validRatio' => 0.0,
                'pausedPercent' => 0.0,
                'hrCoveragePercent' => 0.0,
                'warnings' => ['Activity has insufficient stream samples.'],
                'summary' => 'Insufficient stream data for validation.',
            ];
        }

        $warnings = [];
        $deductions = 0;

        $speedSpikeCount = 0;
        $pausedSeconds = 0;
        $totalMovingSeconds = 0;
        $recordingGapCount = 0;

        for ($i = 0; $i < $count - 1; ++$i) {
            $dt = $timeStream[$i + 1] - $timeStream[$i];
            if ($dt <= 0) {
                continue;
            }

            if ($dt > StreamMath::MAX_RECORDING_GAP_IN_SECONDS) {
                ++$recordingGapCount;
                $pausedSeconds += $dt;
                continue;
            }

            $speed = $velocityStream[$i];
            if (null === $speed || $speed < 0.5) {
                $pausedSeconds += $dt;
            } else {
                $totalMovingSeconds += $dt;
                if ($speed > self::MAX_RUNNING_SPEED_MPS) {
                    ++$speedSpikeCount;
                }
            }
        }

        if ($speedSpikeCount > 3) {
            $warnings[] = sprintf('%d GPS speed spikes (> 43 km/h) detected.', $speedSpikeCount);
            $deductions += min(15, $speedSpikeCount * 2);
        }

        if ($recordingGapCount > 2) {
            $warnings[] = sprintf('%d GPS recording dropouts (> 60s gap) detected.', $recordingGapCount);
            $deductions += min(15, $recordingGapCount * 3);
        }

        // Heart rate coverage
        $hrCoveragePercent = 0.0;
        if (null !== $heartRateStream && [] !== $heartRateStream) {
            $hrCount = count($heartRateStream);
            $validHr = 0;
            $hrSpikeCount = 0;
            $prevHr = null;

            foreach ($heartRateStream as $hr) {
                if (null !== $hr && $hr >= self::MIN_CREDIBLE_HR && $hr <= self::MAX_CREDIBLE_HR) {
                    ++$validHr;
                    if (null !== $prevHr && abs($hr - $prevHr) > 35) {
                        ++$hrSpikeCount;
                    }
                    $prevHr = $hr;
                }
            }

            $hrCoveragePercent = round(($validHr / max(1, $hrCount)) * 100, 1);
            if ($hrCoveragePercent < 80.0) {
                $warnings[] = sprintf('Heart rate sensor dropped out for %.1f%% of trackpoints.', 100 - $hrCoveragePercent);
                $deductions += 15;
            }

            if ($hrSpikeCount > 2) {
                $warnings[] = sprintf('%d abnormal heart rate sensor spikes (> 35 bpm jump) detected.', $hrSpikeCount);
                $deductions += 10;
            }
        } else {
            $warnings[] = 'No heart rate stream recorded in file.';
            $deductions += 10;
        }

        // Cadence check
        if (null !== $cadenceStream && [] !== $cadenceStream) {
            $validCad = count(array_filter($cadenceStream, static fn (?int $c): bool => null !== $c && $c > 40 && $c < 250));
            $cadCoverage = ($validCad / count($cadenceStream)) * 100;
            if ($cadCoverage < 50.0) {
                $warnings[] = 'Cadence sensor was inactive or missing for parts of the run.';
            }
        }

        $totalElapsed = max(1, $totalMovingSeconds + $pausedSeconds);
        $pausedPercent = round(($pausedSeconds / $totalElapsed) * 100, 1);

        $score = max(0, min(100, 100 - $deductions));
        $rating = match (true) {
            $score >= 95 => 'Excellent',
            $score >= 85 => 'Good',
            $score >= 70 => 'Fair',
            default => 'Degraded',
        };

        $badgeColor = match ($rating) {
            'Excellent' => 'text-green-700 bg-green-50 border-green-200',
            'Good' => 'text-emerald-700 bg-emerald-50 border-emerald-200',
            'Fair' => 'text-amber-700 bg-amber-50 border-amber-200',
            default => 'text-red-700 bg-red-50 border-red-200',
        };

        $summary = match ($rating) {
            'Excellent' => 'High-fidelity GPS & continuous heart rate telemetry.',
            'Good' => 'Clean activity track with minor recording dropouts.',
            'Fair' => 'Acceptable data, but contains noticeable sensor dropouts or pauses.',
            default => 'Significant GPS anomalies or sensor gaps observed in trackpoints.',
        };

        return [
            'score' => $score,
            'rating' => $rating,
            'badgeColor' => $badgeColor,
            'validRatio' => round($score / 100.0, 2),
            'pausedPercent' => $pausedPercent,
            'hrCoveragePercent' => $hrCoveragePercent,
            'warnings' => $warnings,
            'summary' => $summary,
        ];
    }
}
