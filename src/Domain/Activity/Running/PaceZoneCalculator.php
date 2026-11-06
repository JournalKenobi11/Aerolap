<?php

declare(strict_types=1);

namespace App\Domain\Activity\Running;

use App\Domain\Athlete\PaceZone\PaceZone;
use App\Domain\Athlete\PaceZone\PaceZones;
use App\Domain\Athlete\PaceZone\TimeInPaceZones;
use App\Domain\Import\FileParser\StreamMath;

final readonly class PaceZoneCalculator
{
    private const float MIN_MOVING_SPEED_METERS_PER_SECOND = 0.5;

    /**
     * Calculate time and distance spent in each running pace zone.
     * Uses strict time-weighting (delta_t between consecutive trackpoint timestamps)
     * so that smart-recording or irregular GPS intervals are accurately weighted.
     *
     * @param list<int> $timeStream Timestamps in seconds from start
     * @param list<?float> $velocityStream Velocity samples in meters per second
     * @param list<bool>|null $movingStream Optional moving boolean flags
     * @param list<?float>|null $distanceStream Optional cumulative distance samples in meters
     */
    public function calculate(
        PaceZones $paceZones,
        array $timeStream,
        array $velocityStream,
        ?array $movingStream = null,
        ?array $distanceStream = null,
    ): TimeInPaceZones {
        $count = min(count($timeStream), count($velocityStream));
        if ($count < 2) {
            return TimeInPaceZones::empty($paceZones);
        }

        $timePerZone = [
            PaceZone::ONE => 0,
            PaceZone::TWO => 0,
            PaceZone::THREE => 0,
            PaceZone::FOUR => 0,
            PaceZone::FIVE => 0,
        ];

        $distancePerZone = [
            PaceZone::ONE => 0.0,
            PaceZone::TWO => 0.0,
            PaceZone::THREE => 0.0,
            PaceZone::FOUR => 0.0,
            PaceZone::FIVE => 0.0,
        ];

        $excludedPausedSeconds = 0;
        $totalElapsedTime = ($timeStream[$count - 1] ?? 0) - ($timeStream[0] ?? 0);

        $smoothedVelocities = count($velocityStream) >= 20
            ? $this->smoothVelocities($velocityStream, 1)
            : $velocityStream;

        for ($i = 0; $i < $count - 1; ++$i) {
            $tCurrent = $timeStream[$i];
            $tNext = $timeStream[$i + 1];
            $deltaT = $tNext - $tCurrent;

            // Handle invalid or negative time jumps
            if ($deltaT <= 0) {
                continue;
            }

            // Cap massive recording gaps (e.g. watch turned off or GPS dropout)
            if ($deltaT > StreamMath::MAX_RECORDING_GAP_IN_SECONDS) {
                $excludedPausedSeconds += $deltaT;
                continue;
            }

            $speed = $smoothedVelocities[$i] ?? $velocityStream[$i];
            $isMovingFlag = null !== $movingStream ? ($movingStream[$i] ?? true) : true;

            // If speed is null or below 0.5 m/s or marked not moving, classify as stationary/pause
            if (null === $speed || $speed < self::MIN_MOVING_SPEED_METERS_PER_SECOND || !$isMovingFlag) {
                $excludedPausedSeconds += $deltaT;
                continue;
            }

            // Calculate interval distance
            $intervalDistance = 0.0;
            if (null !== $distanceStream && isset($distanceStream[$i + 1], $distanceStream[$i])) {
                $dCurrent = $distanceStream[$i];
                $dNext = $distanceStream[$i + 1];
                if (null !== $dCurrent && null !== $dNext && $dNext >= $dCurrent) {
                    $intervalDistance = $dNext - $dCurrent;
                } else {
                    $intervalDistance = $speed * $deltaT;
                }
            } else {
                $intervalDistance = $speed * $deltaT;
            }

            // Match speed to pace zone
            $zone = $paceZones->matchSpeed($speed);
            if ($zone instanceof PaceZone) {
                $zoneName = $zone->getName();
                $timePerZone[$zoneName] = ($timePerZone[$zoneName] ?? 0) + $deltaT;
                $distancePerZone[$zoneName] = ($distancePerZone[$zoneName] ?? 0.0) + $intervalDistance;
            } else {
                // If speed is slower than zone 1 threshold, group under zone 1
                $timePerZone[PaceZone::ONE] = ($timePerZone[PaceZone::ONE] ?? 0) + $deltaT;
                $distancePerZone[PaceZone::ONE] = ($distancePerZone[PaceZone::ONE] ?? 0.0) + $intervalDistance;
            }
        }

        return new TimeInPaceZones(
            paceZones: $paceZones,
            timePerZone: $timePerZone,
            distancePerZone: $distancePerZone,
            excludedPausedSeconds: $excludedPausedSeconds,
            elapsedTimeSeconds: max($totalElapsedTime, array_sum($timePerZone) + $excludedPausedSeconds),
        );
    }

    /**
     * Smooth velocity stream using a moving-average window to filter out
     * 1-second GPS multipath flutter and position jitter common on budget watch antennas.
     *
     * @param list<?float> $velocities
     * @return list<?float>
     */
    private function smoothVelocities(array $velocities, int $radius = 3): array
    {
        $n = count($velocities);
        $smoothed = [];
        for ($i = 0; $i < $n; ++$i) {
            $v = $velocities[$i];
            if (null === $v || $v < self::MIN_MOVING_SPEED_METERS_PER_SECOND) {
                $smoothed[$i] = $v;
                continue;
            }

            $start = max(0, $i - $radius);
            $end = min($n - 1, $i + $radius);
            $window = [];
            for ($k = $start; $k <= $end; ++$k) {
                $val = $velocities[$k];
                if (null !== $val && $val >= self::MIN_MOVING_SPEED_METERS_PER_SECOND) {
                    $window[] = $val;
                }
            }

            $smoothed[$i] = [] !== $window ? array_sum($window) / count($window) : $v;
        }

        return $smoothed;
    }
}
