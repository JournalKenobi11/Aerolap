<?php

declare(strict_types=1);

namespace App\Domain\Athlete\PaceZone;

final class PaceZoneConfiguration
{
    private function __construct(
        private PaceZones $paceZones,
        private PaceZoneMode $mode,
        private ?int $thresholdPaceInSecPerKm = null,
    ) {
    }

    public static function default(): self
    {
        return new self(
            paceZones: PaceZones::default(),
            mode: PaceZoneMode::DEFAULT,
            thresholdPaceInSecPerKm: 300, // 5:00 /km
        );
    }

    public function getPaceZones(): PaceZones
    {
        return $this->paceZones;
    }

    public function getMode(): PaceZoneMode
    {
        return $this->mode;
    }

    public function getThresholdPaceInSecPerKm(): ?int
    {
        return $this->thresholdPaceInSecPerKm;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $modeStr = $config['mode'] ?? 'default';
        $mode = PaceZoneMode::tryFrom($modeStr) ?? PaceZoneMode::DEFAULT;

        if (PaceZoneMode::THRESHOLD === $mode && !empty($config['thresholdPace'])) {
            $threshold = self::parsePaceToSeconds($config['thresholdPace']);
            if ($threshold > 0) {
                return new self(
                    paceZones: PaceZones::fromThresholdPace($threshold),
                    mode: $mode,
                    thresholdPaceInSecPerKm: $threshold
                );
            }
        }

        if (PaceZoneMode::CUSTOM === $mode && !empty($config['zones']) && is_array($config['zones'])) {
            $zonesArray = [];
            foreach (['zone1', 'zone2', 'zone3', 'zone4', 'zone5'] as $key) {
                if (isset($config['zones'][$key]) && is_array($config['zones'][$key])) {
                    $z = $config['zones'][$key];
                    $min = isset($z['min']) && '' !== (string) $z['min'] ? self::parsePaceToSeconds($z['min']) : null;
                    $max = isset($z['max']) && '' !== (string) $z['max'] ? self::parsePaceToSeconds($z['max']) : null;
                    $label = isset($z['label']) && '' !== trim((string) $z['label']) ? trim((string) $z['label']) : null;
                    $zonesArray[$key] = [
                        'min' => $min,
                        'max' => $max,
                        'label' => $label,
                    ];
                }
            }

            if (5 === count($zonesArray)) {
                return new self(
                    paceZones: PaceZones::fromArray($zonesArray),
                    mode: $mode,
                    thresholdPaceInSecPerKm: null
                );
            }
        }

        return self::default();
    }

    /**
     * Accepts either integer seconds (e.g. 300) or clock format "MM:SS" (e.g. "05:00").
     */
    public static function parsePaceToSeconds(mixed $pace): int
    {
        if (is_int($pace)) {
            return $pace;
        }

        if (is_numeric($pace)) {
            return (int) $pace;
        }

        if (is_string($pace) && str_contains($pace, ':')) {
            $parts = explode(':', trim($pace));
            if (2 === count($parts) && is_numeric($parts[0]) && is_numeric($parts[1])) {
                return ((int) $parts[0] * 60) + (int) $parts[1];
            }
        }

        return 0;
    }
}
