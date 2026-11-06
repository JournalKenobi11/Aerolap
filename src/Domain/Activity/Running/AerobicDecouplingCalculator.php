<?php

declare(strict_types=1);

namespace App\Domain\Activity\Running;

use App\Domain\Import\FileParser\StreamMath;

final readonly class AerobicDecouplingCalculator
{
    private const int MIN_TOTAL_DURATION_SECONDS = 1200; // 20 minutes
    private const float MIN_MOVING_SPEED_MPS = 0.5;
    private const float MAX_PACE_CV_FOR_STEADY_RUN = 0.22; // 22% coefficient of variation
    private const float MAX_ELEVATION_GAIN_RATIO = 0.03; // Max 3% grade gain

    /**
     * Calculate Aerobic Decoupling (Pa:Hr / Pw:Hr) and cardiac drift for steady runs.
     *
     * @param list<int> $timeStream Timestamps (seconds from start)
     * @param list<?float> $velocityStream Velocity in m/s
     * @param list<?int> $heartRateStream Heart rate in bpm
     * @param list<?float>|null $altitudeStream Elevation in meters
     * @param list<bool>|null $movingStream Moving flags
     * @param list<?float>|null $distanceStream Distance in meters
     */
    public function calculate(
        array $timeStream,
        array $velocityStream,
        array $heartRateStream,
        ?array $altitudeStream = null,
        ?array $movingStream = null,
        ?array $distanceStream = null,
    ): AerobicDecouplingResult {
        $count = min(count($timeStream), count($velocityStream), count($heartRateStream));
        if ($count < 2) {
            return AerobicDecouplingResult::ineligible('Activity has insufficient data points.');
        }

        $totalDuration = ($timeStream[$count - 1] ?? 0) - ($timeStream[0] ?? 0);
        if ($totalDuration < self::MIN_TOTAL_DURATION_SECONDS) {
            return AerobicDecouplingResult::ineligible(
                sprintf('Activity duration (%d min) is too short. Minimum 20 minutes of steady running required.', (int) round($totalDuration / 60))
            );
        }

        // Filter moving samples and evaluate eligibility
        $movingSamples = [];
        $totalMovingSeconds = 0;
        $validHrCount = 0;
        $totalElevationGain = 0.0;
        $speeds = [];

        for ($i = 0; $i < $count - 1; ++$i) {
            $t1 = $timeStream[$i];
            $t2 = $timeStream[$i + 1];
            $deltaT = $t2 - $t1;

            if ($deltaT <= 0 || $deltaT > StreamMath::MAX_RECORDING_GAP_IN_SECONDS) {
                continue;
            }

            $speed = $velocityStream[$i];
            $hr = $heartRateStream[$i];
            $isMoving = null !== $movingStream ? ($movingStream[$i] ?? true) : true;

            if (null === $speed || $speed < self::MIN_MOVING_SPEED_MPS || !$isMoving) {
                continue;
            }

            $totalMovingSeconds += $deltaT;
            $speeds[] = $speed;

            if (null !== $hr && $hr >= 45 && $hr <= 240) {
                ++$validHrCount;
            }

            $sampleDist = 0.0;
            if (null !== $distanceStream && isset($distanceStream[$i + 1], $distanceStream[$i])) {
                $d1 = $distanceStream[$i];
                $d2 = $distanceStream[$i + 1];
                if (null !== $d1 && null !== $d2 && $d2 >= $d1) {
                    $sampleDist = $d2 - $d1;
                } else {
                    $sampleDist = $speed * $deltaT;
                }
            } else {
                $sampleDist = $speed * $deltaT;
            }

            $elevDelta = 0.0;
            if (null !== $altitudeStream && isset($altitudeStream[$i + 1], $altitudeStream[$i])) {
                $a1 = $altitudeStream[$i];
                $a2 = $altitudeStream[$i + 1];
                if (null !== $a1 && null !== $a2 && $a2 > $a1) {
                    $elevDelta = $a2 - $a1;
                    $totalElevationGain += $elevDelta;
                }
            }

            $movingSamples[] = [
                'time' => $t1,
                'deltaT' => $deltaT,
                'speed' => $speed,
                'hr' => $hr,
                'distance' => $sampleDist,
                'elevGain' => $elevDelta,
            ];
        }

        if ($totalMovingSeconds < self::MIN_TOTAL_DURATION_SECONDS) {
            return AerobicDecouplingResult::ineligible('Moving time is under 20 minutes.');
        }

        if ([] === $movingSamples) {
            return AerobicDecouplingResult::ineligible('No valid moving samples found.');
        }

        // Heart rate data coverage check
        $hrCoverage = $validHrCount / count($movingSamples);
        if ($hrCoverage < 0.75) {
            return AerobicDecouplingResult::ineligible(
                sprintf('Heart rate sensor data is missing for %d%% of the run.', (int) round((1 - $hrCoverage) * 100))
            );
        }

        // Check pace variability (CV) to detect interval workouts / stop-and-go runs
        $meanSpeed = array_sum($speeds) / count($speeds);
        $variance = 0.0;
        foreach ($speeds as $s) {
            $variance += ($s - $meanSpeed) ** 2;
        }
        $stdSpeed = sqrt($variance / count($speeds));
        $cvSpeed = $meanSpeed > 0 ? $stdSpeed / $meanSpeed : 1.0;

        if ($cvSpeed > self::MAX_PACE_CV_FOR_STEADY_RUN) {
            return AerobicDecouplingResult::ineligible(
                sprintf('High pace variability detected (CV %.1f%%). Decoupling analysis requires a steady aerobic run, not interval or stop-and-go sessions.', $cvSpeed * 100)
            );
        }

        // Exclude initial warm-up period (first 10 min for runs >= 30m, 5 min for runs 20-30m)
        $warmupSeconds = $totalMovingSeconds >= 1800 ? 600 : 300;
        $cooldownSeconds = $totalMovingSeconds >= 1800 ? 120 : 60;

        $eligibleSamples = [];
        $accumulatedSeconds = 0;
        $totalAnalyzedSeconds = 0;

        foreach ($movingSamples as $sample) {
            $accumulatedSeconds += $sample['deltaT'];
            if ($accumulatedSeconds <= $warmupSeconds) {
                continue; // Skip warmup
            }
            if ($accumulatedSeconds > ($totalMovingSeconds - $cooldownSeconds)) {
                continue; // Skip cooldown
            }
            $eligibleSamples[] = $sample;
            $totalAnalyzedSeconds += $sample['deltaT'];
        }

        if ($totalAnalyzedSeconds < 600 || count($eligibleSamples) < 20) {
            return AerobicDecouplingResult::ineligible('Insufficient steady segment remaining after warm-up/cool-down trimming.');
        }

        // Split into two equal duration halves
        $halfDurationTarget = $totalAnalyzedSeconds / 2.0;

        $half1Samples = [];
        $half2Samples = [];
        $currentHalfSec = 0.0;

        foreach ($eligibleSamples as $sample) {
            if ($currentHalfSec < $halfDurationTarget) {
                $half1Samples[] = $sample;
                $currentHalfSec += $sample['deltaT'];
            } else {
                $half2Samples[] = $sample;
            }
        }

        if ([] === $half1Samples || [] === $half2Samples) {
            return AerobicDecouplingResult::ineligible('Unable to divide steady portion into two halves.');
        }

        $calcHalf = function (array $samples): array {
            $duration = 0;
            $distance = 0.0;
            $weightedSpeedSum = 0.0;
            $weightedHrSum = 0.0;
            $hrDuration = 0;

            foreach ($samples as $s) {
                $dt = $s['deltaT'];
                $duration += $dt;
                $distance += $s['distance'];
                $weightedSpeedSum += $s['speed'] * $dt;

                if (null !== $s['hr'] && $s['hr'] >= 45) {
                    $weightedHrSum += $s['hr'] * $dt;
                    $hrDuration += $dt;
                }
            }

            $avgSpeed = $duration > 0 ? $weightedSpeedSum / $duration : 0.0;
            $avgHr = $hrDuration > 0 ? $weightedHrSum / $hrDuration : 0.0;
            $efficiencyFactor = ($avgHr > 0) ? ($avgSpeed / $avgHr) : 0.0;

            return [
                'duration' => $duration,
                'distance' => $distance,
                'avgSpeed' => $avgSpeed,
                'avgHr' => $avgHr,
                'ef' => $efficiencyFactor,
            ];
        };

        $h1 = $calcHalf($half1Samples);
        $h2 = $calcHalf($half2Samples);

        if ($h1['ef'] <= 0.0 || $h2['ef'] <= 0.0) {
            return AerobicDecouplingResult::ineligible('Insufficient heart rate data during steady halves.');
        }

        // Decoupling % = ((EF1 - EF2) / EF1) * 100
        $decouplingPct = (($h1['ef'] - $h2['ef']) / $h1['ef']) * 100.0;
        $hrDrift = $h2['avgHr'] - $h1['avgHr'];

        $pace1 = $h1['avgSpeed'] > 0 ? (int) round(1000.0 / $h1['avgSpeed']) : 0;
        $pace2 = $h2['avgSpeed'] > 0 ? (int) round(1000.0 / $h2['avgSpeed']) : 0;
        $paceDrift = $pace2 - $pace1;

        return new AerobicDecouplingResult(
            isEligible: true,
            ineligibilityReason: null,
            halfOneDuration: $h1['duration'],
            halfTwoDuration: $h2['duration'],
            halfOneDistanceMeters: $h1['distance'],
            halfTwoDistanceMeters: $h2['distance'],
            halfOneAvgSpeedMps: $h1['avgSpeed'],
            halfTwoAvgSpeedMps: $h2['avgSpeed'],
            halfOneAvgHeartRate: $h1['avgHr'],
            halfTwoAvgHeartRate: $h2['avgHr'],
            halfOneEfficiencyFactor: $h1['ef'],
            halfTwoEfficiencyFactor: $h2['ef'],
            decouplingPercentage: $decouplingPct,
            heartRateDriftBpm: $hrDrift,
            paceDriftSecPerKm: $paceDrift,
        );
    }
}
