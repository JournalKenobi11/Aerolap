<?php

declare(strict_types=1);

namespace App\Domain\Activity\Running;

use App\Infrastructure\Measurement\Length\Kilometer;
use App\Infrastructure\Measurement\Length\Meter;
use App\Infrastructure\Measurement\UnitSystem;
use App\Infrastructure\Measurement\Velocity\SecPerKm;
use App\Infrastructure\Time\Format\ProvideTimeFormats;

final readonly class RacePrediction
{
    use ProvideTimeFormats;

    public function __construct(
        private int $targetDistanceMeters,
        private string $targetLabel,
        private int $predictedTimeSeconds,
        private int $lowerBoundSeconds,
        private int $upperBoundSeconds,
        private string $confidenceLevel, // 'High', 'Moderate', 'Low'
        private string $sourceActivityId,
        private string $sourceActivityName,
        private string $sourceDate,
        private int $sourceDistanceMeters,
        private int $sourceTimeSeconds,
        private float $fatigueExponent = 1.06,
        private ?string $notes = null,
    ) {
    }

    public function getTargetDistanceMeters(): int
    {
        return $this->targetDistanceMeters;
    }

    public function getTargetLabel(): string
    {
        return $this->targetLabel;
    }

    public function getPredictedTimeSeconds(): int
    {
        return $this->predictedTimeSeconds;
    }

    public function getFormattedPredictedTime(): string
    {
        return $this->formatDurationAsClock($this->predictedTimeSeconds);
    }

    public function getFormattedRange(): string
    {
        return sprintf(
            '%s - %s',
            $this->formatDurationAsClock($this->lowerBoundSeconds),
            $this->formatDurationAsClock($this->upperBoundSeconds)
        );
    }

    public function getPredictedPace(UnitSystem $unitSystem = UnitSystem::METRIC): string
    {
        $secPerKm = (int) round(($this->predictedTimeSeconds / $this->targetDistanceMeters) * 1000);
        $paceObj = SecPerKm::from($secPerKm);
        $sec = UnitSystem::IMPERIAL === $unitSystem
            ? (int) round($paceObj->toSecPerMile()->toFloat())
            : $secPerKm;
        $symbol = UnitSystem::IMPERIAL === $unitSystem ? '/mi' : '/km';

        return sprintf('%s %s', $this->formatDurationAsClock($sec), $symbol);
    }

    public function getConfidenceLevel(): string
    {
        return $this->confidenceLevel;
    }

    public function getConfidenceColor(): string
    {
        return match ($this->confidenceLevel) {
            'High' => 'text-green-600 bg-green-50 border-green-200',
            'Moderate' => 'text-amber-600 bg-amber-50 border-amber-200',
            default => 'text-gray-600 bg-gray-50 border-gray-200',
        };
    }

    public function getSourceActivityId(): string
    {
        return $this->sourceActivityId;
    }

    public function getSourceActivityName(): string
    {
        return $this->sourceActivityName;
    }

    public function getSourceDate(): string
    {
        return $this->sourceDate;
    }

    public function getSourceDistanceMeters(): int
    {
        return $this->sourceDistanceMeters;
    }

    public function getFormattedSourceDistance(): string
    {
        return sprintf('%.1f km', $this->sourceDistanceMeters / 1000.0);
    }

    public function getFormattedSourceTime(): string
    {
        return $this->formatDurationAsClock($this->sourceTimeSeconds);
    }

    public function getFatigueExponent(): float
    {
        return $this->fatigueExponent;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }
}
