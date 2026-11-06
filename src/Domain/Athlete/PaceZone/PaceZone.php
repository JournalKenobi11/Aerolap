<?php

declare(strict_types=1);

namespace App\Domain\Athlete\PaceZone;

use App\Infrastructure\Measurement\UnitSystem;
use App\Infrastructure\Measurement\Velocity\SecPerKm;
use App\Infrastructure\Time\Format\ProvideTimeFormats;

final readonly class PaceZone
{
    use ProvideTimeFormats;

    public const string ONE = 'zone1';
    public const string TWO = 'zone2';
    public const string THREE = 'zone3';
    public const string FOUR = 'zone4';
    public const string FIVE = 'zone5';

    /**
     * @var array<string, string>
     */
    public const array COLORS = [
        self::ONE => '#3B82F6',    // Blue: Recovery / Easy
        self::TWO => '#10B981',    // Green: Aerobic / Endurance
        self::THREE => '#F59E0B',  // Amber: Tempo
        self::FOUR => '#EF4444',   // Red: Threshold
        self::FIVE => '#8B5CF6',   // Purple: Anaerobic / Interval
    ];

    /**
     * @var array<string, string>
     */
    public const array DEFAULT_LABELS = [
        self::ONE => 'Recovery',
        self::TWO => 'Aerobic',
        self::THREE => 'Tempo',
        self::FOUR => 'Threshold',
        self::FIVE => 'Anaerobic',
    ];

    public function __construct(
        private string $name,
        private string $label,
        private ?int $minPaceInSecPerKm, // Fastest pace boundary (lower sec/km). null for Z5
        private ?int $maxPaceInSecPerKm, // Slowest pace boundary (higher sec/km). null for Z1
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getColor(): string
    {
        return self::COLORS[$this->name] ?? '#6B7280';
    }

    public function getMinPaceInSecPerKm(): ?int
    {
        return $this->minPaceInSecPerKm;
    }

    public function getMaxPaceInSecPerKm(): ?int
    {
        return $this->maxPaceInSecPerKm;
    }

    /**
     * Test whether a speed in m/s falls inside this pace zone.
     * Stationary speeds (< 0.5 m/s) should be handled beforehand.
     */
    public function matchesSpeed(float $metersPerSecond): bool
    {
        if ($metersPerSecond <= 0.0) {
            return false;
        }

        $paceInSecPerKm = 1000.0 / $metersPerSecond;

        // If minPace is set, pace must be >= minPace (cannot be faster than zone limit)
        if (null !== $this->minPaceInSecPerKm && $paceInSecPerKm < $this->minPaceInSecPerKm) {
            return false;
        }

        // If maxPace is set, pace must be < maxPace (cannot be slower than zone limit)
        if (null !== $this->maxPaceInSecPerKm && $paceInSecPerKm >= $this->maxPaceInSecPerKm) {
            return false;
        }

        return true;
    }

    public function getFormattedRange(UnitSystem $unitSystem = UnitSystem::METRIC): string
    {
        $symbol = UnitSystem::IMPERIAL === $unitSystem ? '/mi' : '/km';

        $formatPace = function (?int $secPerKm) use ($unitSystem): ?string {
            if (null === $secPerKm) {
                return null;
            }
            $paceObj = SecPerKm::from($secPerKm);
            if (UnitSystem::IMPERIAL === $unitSystem) {
                $sec = (int) round($paceObj->toSecPerMile()->toFloat());
            } else {
                $sec = $secPerKm;
            }

            return $this->formatDurationAsClock($sec);
        };

        $minFormatted = $formatPace($this->minPaceInSecPerKm);
        $maxFormatted = $formatPace($this->maxPaceInSecPerKm);

        if (null === $this->minPaceInSecPerKm && null !== $maxFormatted) {
            return sprintf('< %s %s', $maxFormatted, $symbol);
        }

        if (null === $this->maxPaceInSecPerKm && null !== $minFormatted) {
            return sprintf('> %s %s', $minFormatted, $symbol);
        }

        if (null !== $minFormatted && null !== $maxFormatted) {
            return sprintf('%s - %s %s', $minFormatted, $maxFormatted, $symbol);
        }

        return '-';
    }
}
