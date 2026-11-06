<?php

declare(strict_types=1);

namespace App\Domain\Activity\Running;

use App\Infrastructure\Measurement\Length\Kilometer;
use App\Infrastructure\Measurement\UnitSystem;
use App\Infrastructure\Measurement\Velocity\KmPerHour;
use App\Infrastructure\Measurement\Velocity\MetersPerSecond;
use App\Infrastructure\Measurement\Velocity\SecPerKm;
use App\Infrastructure\Time\Format\ProvideTimeFormats;

final readonly class AerobicDecouplingResult
{
    use ProvideTimeFormats;

    public function __construct(
        private bool $isEligible,
        private ?string $ineligibilityReason = null,
        private int $halfOneDuration = 0,
        private int $halfTwoDuration = 0,
        private float $halfOneDistanceMeters = 0.0,
        private float $halfTwoDistanceMeters = 0.0,
        private float $halfOneAvgSpeedMps = 0.0,
        private float $halfTwoAvgSpeedMps = 0.0,
        private float $halfOneAvgHeartRate = 0.0,
        private float $halfTwoAvgHeartRate = 0.0,
        private float $halfOneEfficiencyFactor = 0.0,
        private float $halfTwoEfficiencyFactor = 0.0,
        private float $decouplingPercentage = 0.0,
        private float $heartRateDriftBpm = 0.0,
        private int $paceDriftSecPerKm = 0,
    ) {
    }

    public static function ineligible(string $reason): self
    {
        return new self(
            isEligible: false,
            ineligibilityReason: $reason
        );
    }

    public function isEligible(): bool
    {
        return $this->isEligible;
    }

    public function getIneligibilityReason(): ?string
    {
        return $this->ineligibilityReason;
    }

    public function getHalfOneDuration(): int
    {
        return $this->halfOneDuration;
    }

    public function getHalfTwoDuration(): int
    {
        return $this->halfTwoDuration;
    }

    public function getHalfOneDistance(): Kilometer
    {
        return Kilometer::from(round($this->halfOneDistanceMeters / 1000, 2));
    }

    public function getHalfTwoDistance(): Kilometer
    {
        return Kilometer::from(round($this->halfTwoDistanceMeters / 1000, 2));
    }

    public function getHalfOneAvgHeartRate(): int
    {
        return (int) round($this->halfOneAvgHeartRate);
    }

    public function getHalfTwoAvgHeartRate(): int
    {
        return (int) round($this->halfTwoAvgHeartRate);
    }

    public function getHalfOneAvgPace(): SecPerKm
    {
        if ($this->halfOneAvgSpeedMps <= 0.0) {
            return SecPerKm::from(0);
        }

        return MetersPerSecond::from($this->halfOneAvgSpeedMps)->toSecPerKm();
    }

    public function getHalfTwoAvgPace(): SecPerKm
    {
        if ($this->halfTwoAvgSpeedMps <= 0.0) {
            return SecPerKm::from(0);
        }

        return MetersPerSecond::from($this->halfTwoAvgSpeedMps)->toSecPerKm();
    }

    public function getHalfOneEfficiencyFactor(): float
    {
        return round($this->halfOneEfficiencyFactor, 4);
    }

    public function getHalfTwoEfficiencyFactor(): float
    {
        return round($this->halfTwoEfficiencyFactor, 4);
    }

    public function getDecouplingPercentage(): float
    {
        return round($this->decouplingPercentage, 1);
    }

    public function getHeartRateDriftBpm(): float
    {
        return round($this->heartRateDriftBpm, 1);
    }

    public function getPaceDriftSecPerKm(): int
    {
        return $this->paceDriftSecPerKm;
    }

    /**
     * Assessment according to endurance sports physiology (Joe Friel standard):
     * < 5%: Strong aerobic base, minimal cardiac drift.
     * 5% - 10%: Moderate decoupling, slight aerobic base fatigue.
     * > 10%: High decoupling, cardiac drift indicates fatigue, dehydration, or running above aerobic threshold.
     */
    public function getAssessment(): string
    {
        if (!$this->isEligible) {
            return 'Not eligible for decoupling analysis';
        }

        $pct = $this->decouplingPercentage;
        if ($pct < 3.0) {
            return 'Excellent Aerobic Base (< 3% decoupling - highly stable)';
        }
        if ($pct < 5.0) {
            return 'Solid Aerobic Base (< 5% decoupling - steady performance)';
        }
        if ($pct < 10.0) {
            return 'Moderate Decoupling (5-10% - mild aerobic fatigue)';
        }

        return 'Significant Decoupling (> 10% - notable cardiac drift)';
    }

    public function getAssessmentColor(): string
    {
        if (!$this->isEligible) {
            return 'text-gray-500';
        }

        $pct = $this->decouplingPercentage;
        if ($pct < 5.0) {
            return 'text-green-600';
        }
        if ($pct < 10.0) {
            return 'text-amber-500';
        }

        return 'text-red-500';
    }

    public function getFormattedPace(SecPerKm $pace, UnitSystem $unitSystem = UnitSystem::METRIC): string
    {
        $sec = UnitSystem::IMPERIAL === $unitSystem
            ? (int) round($pace->toSecPerMile()->toFloat())
            : $pace->toInt();

        return $this->formatDurationAsClock($sec);
    }
}
