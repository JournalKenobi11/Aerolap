<?php

declare(strict_types=1);

namespace App\Domain\Activity\Running;

use App\Domain\Athlete\HeartRateZone\TimeInHeartRateZones;

final readonly class RunningEffortScore
{
    public const array ZONE_WEIGHTS = [
        1 => 1.0,  // Recovery
        2 => 2.0,  // Aerobic
        3 => 4.0,  // Tempo
        4 => 7.0,  // Threshold
        5 => 11.0, // Anaerobic / VO2max
    ];

    public function __construct(
        private int $score,
        private string $label,
        private string $description,
        private int $estimatedRecoveryHours,
        private string $recoveryAdvice,
    ) {
    }

    public static function fromHeartRateZones(?TimeInHeartRateZones $zones, int $movingTimeSeconds): self
    {
        if (null === $zones || 0 === $zones->getTotalTimeInSeconds()) {
            // Fallback estimation when no HR zones exist: based purely on moving time at moderate effort
            $minutes = max(1, (int) round($movingTimeSeconds / 60));
            $score = (int) round($minutes * 0.5);

            return self::buildFromScore($score);
        }

        $z1Min = $zones->getTimeInZoneOne() / 60.0;
        $z2Min = $zones->getTimeInZoneTwo() / 60.0;
        $z3Min = $zones->getTimeInZoneThree() / 60.0;
        $z4Min = $zones->getTimeInZoneFour() / 60.0;
        $z5Min = $zones->getTimeInZoneFive() / 60.0;

        $rawScore = ($z1Min * self::ZONE_WEIGHTS[1])
            + ($z2Min * self::ZONE_WEIGHTS[2])
            + ($z3Min * self::ZONE_WEIGHTS[3])
            + ($z4Min * self::ZONE_WEIGHTS[4])
            + ($z5Min * self::ZONE_WEIGHTS[5]);

        // Normalize scaling factor so a 60-minute Zone 2 run is ~30, a hard 10k is ~70-90
        $score = (int) round($rawScore * 0.25);

        return self::buildFromScore($score);
    }

    public static function buildFromScore(int $score): self
    {
        return match (true) {
            $score < 25 => new self(
                score: $score,
                label: 'Light / Recovery',
                description: 'Low cardiac strain. Ideal for recovery, aerobic base maintenance, or warm-ups.',
                estimatedRecoveryHours: 12,
                recoveryAdvice: 'Minimal recovery required. Ready for standard or quality training tomorrow.'
            ),
            $score < 55 => new self(
                score: $score,
                label: 'Moderate Aerobic',
                description: 'Solid aerobic cardiovascular stimulus. Builds endurance without excessive muscular fatigue.',
                estimatedRecoveryHours: 24,
                recoveryAdvice: 'Full recovery expected within 18-24 hours. A good night\'s rest will restore readiness.'
            ),
            $score < 85 => new self(
                score: $score,
                label: 'Hard / Sustained',
                description: 'Substantial training stimulus. High sustained effort or longer distance.',
                estimatedRecoveryHours: 36,
                recoveryAdvice: 'Allow 24 to 36 hours before another high-intensity session. Zone 1/2 recovery run is fine.'
            ),
            $score < 120 => new self(
                score: $score,
                label: 'Very Hard / Threshold',
                description: 'Very heavy cardiovascular and metabolic strain. Threshold intervals or hard long run.',
                estimatedRecoveryHours: 48,
                recoveryAdvice: 'High fatigue accumulated. Plan an easy recovery day or rest day before your next hard effort.'
            ),
            default => new self(
                score: $score,
                label: 'Extreme / Race Effort',
                description: 'Maximum exertion session. Near-maximal race effort or exhaustive training day.',
                estimatedRecoveryHours: 72,
                recoveryAdvice: 'Significant cellular and muscular fatigue. Prioritize sleep, nutrition, and 2 full recovery days.'
            ),
        };
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getEstimatedRecoveryHours(): int
    {
        return $this->estimatedRecoveryHours;
    }

    public function getRecoveryAdvice(): string
    {
        return $this->recoveryAdvice;
    }

    public function getBadgeColor(): string
    {
        return match (true) {
            $this->score < 25 => 'text-blue-700 bg-blue-50 border-blue-200',
            $this->score < 55 => 'text-green-700 bg-green-50 border-green-200',
            $this->score < 85 => 'text-amber-700 bg-amber-50 border-amber-200',
            $this->score < 120 => 'text-orange-700 bg-orange-50 border-orange-200',
            default => 'text-red-700 bg-red-50 border-red-200',
        };
    }
}
