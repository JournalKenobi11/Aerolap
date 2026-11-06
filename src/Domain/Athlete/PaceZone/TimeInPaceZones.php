<?php

declare(strict_types=1);

namespace App\Domain\Athlete\PaceZone;

use App\Infrastructure\Measurement\Length\Kilometer;
use App\Infrastructure\Measurement\Length\Meter;
use App\Infrastructure\Measurement\UnitSystem;
use App\Infrastructure\Measurement\Velocity\KmPerHour;
use App\Infrastructure\Measurement\Velocity\MetersPerSecond;
use App\Infrastructure\Measurement\Velocity\SecPerKm;
use App\Infrastructure\Time\Format\ProvideTimeFormats;

final readonly class TimeInPaceZones
{
    use ProvideTimeFormats;

    private int $totalMovingTimeInSeconds;
    private float $totalMovingDistanceInMeters;

    public function __construct(
        private PaceZones $paceZones,
        /** @var array<string, int> Zone name => seconds */
        private array $timePerZone,
        /** @var array<string, float> Zone name => meters */
        private array $distancePerZone,
        private int $excludedPausedSeconds,
        private int $elapsedTimeSeconds,
    ) {
        $this->totalMovingTimeInSeconds = array_sum($this->timePerZone);
        $this->totalMovingDistanceInMeters = array_sum($this->distancePerZone);
    }

    public static function empty(PaceZones $paceZones): self
    {
        return new self(
            paceZones: $paceZones,
            timePerZone: [
                PaceZone::ONE => 0,
                PaceZone::TWO => 0,
                PaceZone::THREE => 0,
                PaceZone::FOUR => 0,
                PaceZone::FIVE => 0,
            ],
            distancePerZone: [
                PaceZone::ONE => 0.0,
                PaceZone::TWO => 0.0,
                PaceZone::THREE => 0.0,
                PaceZone::FOUR => 0.0,
                PaceZone::FIVE => 0.0,
            ],
            excludedPausedSeconds: 0,
            elapsedTimeSeconds: 0,
        );
    }

    public function getPaceZones(): PaceZones
    {
        return $this->paceZones;
    }

    public function getTimeInZone(string $zoneName): int
    {
        return $this->timePerZone[$zoneName] ?? 0;
    }

    public function getDistanceInZone(string $zoneName): Meter
    {
        return Meter::from(round($this->distancePerZone[$zoneName] ?? 0.0));
    }

    public function getPercentageInZone(string $zoneName): float
    {
        if (0 === $this->totalMovingTimeInSeconds) {
            return 0.0;
        }

        return round(($this->getTimeInZone($zoneName) / $this->totalMovingTimeInSeconds) * 100, 1);
    }

    public function getAverageSpeedInZone(string $zoneName): ?KmPerHour
    {
        $time = $this->getTimeInZone($zoneName);
        $dist = $this->distancePerZone[$zoneName] ?? 0.0;
        if ($time <= 0 || $dist <= 0) {
            return null;
        }

        return MetersPerSecond::from($dist / $time)->toKmPerHour();
    }

    public function getAveragePaceInZone(string $zoneName): ?SecPerKm
    {
        $time = $this->getTimeInZone($zoneName);
        $dist = $this->distancePerZone[$zoneName] ?? 0.0;
        if ($time <= 0 || $dist <= 0) {
            return null;
        }

        return SecPerKm::from(round(($time / $dist) * 1000));
    }

    public function getTotalMovingTimeInSeconds(): int
    {
        return $this->totalMovingTimeInSeconds;
    }

    public function getTotalMovingDistance(): Kilometer
    {
        return Kilometer::from(round($this->totalMovingDistanceInMeters / 1000, 3));
    }

    public function getExcludedPausedSeconds(): int
    {
        return $this->excludedPausedSeconds;
    }

    public function getExcludedPercentage(): float
    {
        if (0 === $this->elapsedTimeSeconds) {
            return 0.0;
        }

        return round(($this->excludedPausedSeconds / $this->elapsedTimeSeconds) * 100, 1);
    }

    public function getElapsedTimeSeconds(): int
    {
        return $this->elapsedTimeSeconds;
    }

    /**
     * @return array<int, array{
     *     name: string,
     *     label: string,
     *     color: string,
     *     range: string,
     *     seconds: int,
     *     formattedTime: string,
     *     percentage: float,
     *     distanceMeters: float,
     *     formattedPace: string
     * }>
     */
    public function toViewRows(UnitSystem $unitSystem = UnitSystem::METRIC): array
    {
        $rows = [];
        foreach ($this->paceZones->getZones() as $zone) {
            $name = $zone->getName();
            $seconds = $this->getTimeInZone($name);
            $avgPace = $this->getAveragePaceInZone($name);
            $formattedPace = '-';
            if ($avgPace instanceof SecPerKm) {
                $paceVal = UnitSystem::IMPERIAL === $unitSystem
                    ? (int) round($avgPace->toSecPerMile()->toFloat())
                    : $avgPace->toInt();
                $formattedPace = $this->formatDurationAsClock($paceVal);
            }

            $rows[] = [
                'name' => $name,
                'label' => $zone->getLabel(),
                'color' => $zone->getColor(),
                'range' => $zone->getFormattedRange($unitSystem),
                'seconds' => $seconds,
                'formattedTime' => $this->formatDurationAsClock($seconds),
                'percentage' => $this->getPercentageInZone($name),
                'distanceMeters' => $this->distancePerZone[$name] ?? 0.0,
                'formattedPace' => $formattedPace,
            ];
        }

        return $rows;
    }
}
