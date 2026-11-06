<?php

declare(strict_types=1);

namespace App\Domain\Athlete\PaceZone;

final readonly class PaceZones
{
    private function __construct(
        private PaceZone $zoneOne,
        private PaceZone $zoneTwo,
        private PaceZone $zoneThree,
        private PaceZone $zoneFour,
        private PaceZone $zoneFive,
    ) {
    }

    public static function create(
        PaceZone $zoneOne,
        PaceZone $zoneTwo,
        PaceZone $zoneThree,
        PaceZone $zoneFour,
        PaceZone $zoneFive,
    ): self {
        return new self($zoneOne, $zoneTwo, $zoneThree, $zoneFour, $zoneFive);
    }

    /**
     * @return PaceZone[]
     */
    public function getZones(): array
    {
        return [
            $this->zoneOne,
            $this->zoneTwo,
            $this->zoneThree,
            $this->zoneFour,
            $this->zoneFive,
        ];
    }

    public function getZoneOne(): PaceZone
    {
        return $this->zoneOne;
    }

    public function getZoneTwo(): PaceZone
    {
        return $this->zoneTwo;
    }

    public function getZoneThree(): PaceZone
    {
        return $this->zoneThree;
    }

    public function getZoneFour(): PaceZone
    {
        return $this->zoneFour;
    }

    public function getZoneFive(): PaceZone
    {
        return $this->zoneFive;
    }

    public function matchSpeed(float $speedInMetersPerSecond): ?PaceZone
    {
        if ($speedInMetersPerSecond < 0.5) {
            // Stationary / pause
            return null;
        }

        foreach ($this->getZones() as $zone) {
            if ($zone->matchesSpeed($speedInMetersPerSecond)) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * Build 5 pace zones from a runner's functional threshold pace (in seconds per km).
     * Standard Jack Daniels / Joe Friel percentage boundaries:
     * - Zone 1: > 129% of threshold pace
     * - Zone 2: 114% - 129%
     * - Zone 3: 106% - 113%
     * - Zone 4: 98% - 105% (Threshold)
     * - Zone 5: < 97% (Anaerobic / Interval)
     */
    public static function fromThresholdPace(int $thresholdSecPerKm): self
    {
        if ($thresholdSecPerKm <= 0) {
            throw new InvalidPaceZoneConfiguration('Threshold pace must be a positive number of seconds per km.');
        }

        $z1MinPace = (int) round($thresholdSecPerKm * 1.29);
        $z2MinPace = (int) round($thresholdSecPerKm * 1.14);
        $z3MinPace = (int) round($thresholdSecPerKm * 1.06);
        $z4MinPace = (int) round($thresholdSecPerKm * 0.98);

        return new self(
            zoneOne: new PaceZone(PaceZone::ONE, PaceZone::DEFAULT_LABELS[PaceZone::ONE], $z1MinPace, null),
            zoneTwo: new PaceZone(PaceZone::TWO, PaceZone::DEFAULT_LABELS[PaceZone::TWO], $z2MinPace, $z1MinPace),
            zoneThree: new PaceZone(PaceZone::THREE, PaceZone::DEFAULT_LABELS[PaceZone::THREE], $z3MinPace, $z2MinPace),
            zoneFour: new PaceZone(PaceZone::FOUR, PaceZone::DEFAULT_LABELS[PaceZone::FOUR], $z4MinPace, $z3MinPace),
            zoneFive: new PaceZone(PaceZone::FIVE, PaceZone::DEFAULT_LABELS[PaceZone::FIVE], null, $z4MinPace),
        );
    }

    public static function default(): self
    {
        return new self(
            zoneOne: new PaceZone(PaceZone::ONE, PaceZone::DEFAULT_LABELS[PaceZone::ONE], 390, null),      // > 6:30 /km
            zoneTwo: new PaceZone(PaceZone::TWO, PaceZone::DEFAULT_LABELS[PaceZone::TWO], 345, 390),     // 5:45 - 6:30 /km
            zoneThree: new PaceZone(PaceZone::THREE, PaceZone::DEFAULT_LABELS[PaceZone::THREE], 300, 345), // 5:00 - 5:45 /km
            zoneFour: new PaceZone(PaceZone::FOUR, PaceZone::DEFAULT_LABELS[PaceZone::FOUR], 270, 300),  // 4:30 - 5:00 /km
            zoneFive: new PaceZone(PaceZone::FIVE, PaceZone::DEFAULT_LABELS[PaceZone::FIVE], null, 270),  // < 4:30 /km
        );
    }

    /**
     * @param array<string, array{min: ?int, max: ?int, label?: string}> $zones
     */
    public static function fromArray(array $zones): self
    {
        $getLabel = static fn (string $key, ?string $label): string => $label ?? PaceZone::DEFAULT_LABELS[$key] ?? $key;

        return new self(
            zoneOne: new PaceZone(
                PaceZone::ONE,
                $getLabel(PaceZone::ONE, $zones['zone1']['label'] ?? null),
                $zones['zone1']['min'] ?? 390,
                $zones['zone1']['max'] ?? null
            ),
            zoneTwo: new PaceZone(
                PaceZone::TWO,
                $getLabel(PaceZone::TWO, $zones['zone2']['label'] ?? null),
                $zones['zone2']['min'] ?? 345,
                $zones['zone2']['max'] ?? 390
            ),
            zoneThree: new PaceZone(
                PaceZone::THREE,
                $getLabel(PaceZone::THREE, $zones['zone3']['label'] ?? null),
                $zones['zone3']['min'] ?? 300,
                $zones['zone3']['max'] ?? 345
            ),
            zoneFour: new PaceZone(
                PaceZone::FOUR,
                $getLabel(PaceZone::FOUR, $zones['zone4']['label'] ?? null),
                $zones['zone4']['min'] ?? 270,
                $zones['zone4']['max'] ?? 300
            ),
            zoneFive: new PaceZone(
                PaceZone::FIVE,
                $getLabel(PaceZone::FIVE, $zones['zone5']['label'] ?? null),
                $zones['zone5']['min'] ?? null,
                $zones['zone5']['max'] ?? 270
            ),
        );
    }
}
