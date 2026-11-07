<?php

declare(strict_types=1);

namespace App\Domain\Activity\Running\TrainingStress;

use App\Infrastructure\Serialization\Json;

final readonly class TrainingStressConfig
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private array $data,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public static function loadFromFile(string $filePath): self
    {
        if (!file_exists($filePath)) {
            return new self([]);
        }

        try {
            $content = (string) file_get_contents($filePath);
            $parsed = Json::decode($content);

            return new self(is_array($parsed) ? $parsed : []);
        } catch (\Throwable) {
            return new self([]);
        }
    }

    public function getConfigVersion(): string
    {
        return (string) ($this->data['config_version'] ?? '1.0.0');
    }

    public function getMaxHr(): int
    {
        return (int) ($this->data['athlete']['max_hr'] ?? 194);
    }

    public function getRestingHr(): int
    {
        return (int) ($this->data['athlete']['resting_hr'] ?? 55);
    }

    public function getHrCalcMethod(): string
    {
        return (string) ($this->data['athlete']['hr_calc_method'] ?? 'Heart Rate Reserve (HRR)');
    }

    public function getAcuteDays(): int
    {
        return (int) ($this->data['windows']['acute_days'] ?? 7);
    }

    public function getChronicPriorDays(): int
    {
        return (int) ($this->data['windows']['chronic_prior_days'] ?? 21);
    }

    public function getLongRunHistoryDays(): int
    {
        return (int) ($this->data['windows']['long_run_history_days'] ?? 30);
    }

    public function getInsufficientHistoryDays(): int
    {
        return (int) ($this->data['windows']['insufficient_history_days'] ?? 21);
    }

    public function getRunWeight(): float
    {
        return (float) ($this->data['mechanical_weights']['run'] ?? 1.0);
    }

    public function getWalkWeight(): float
    {
        return (float) ($this->data['mechanical_weights']['walk'] ?? 0.25);
    }

    public function getHikeWeight(): float
    {
        return (float) ($this->data['mechanical_weights']['hike'] ?? 0.40);
    }

    public function getIntensityWeightZ4Z5(): float
    {
        return (float) ($this->data['mechanical_weights']['intensity_weight_z4_z5'] ?? 1.25);
    }

    public function getVolumeStepFloorKm(): float
    {
        return (float) ($this->data['volume_step']['floor_km'] ?? 2.5);
    }

    public function getVolumeStepPct(): float
    {
        return (float) ($this->data['volume_step']['pct'] ?? 0.10);
    }

    public function getVolumeStepWatchBufferKm(): float
    {
        return (float) ($this->data['volume_step']['watch_buffer_km'] ?? 2.5);
    }

    public function getLongRunFlatThresholdKm(): float
    {
        return (float) ($this->data['long_run_step']['flat_threshold_km'] ?? 1.2);
    }

    public function getLongRunCutoffDistanceKm(): float
    {
        return (float) ($this->data['long_run_step']['cutoff_distance_km'] ?? 10.0);
    }

    public function getLongRunPercentageAboveCutoff(): float
    {
        return (float) ($this->data['long_run_step']['percentage_above_cutoff'] ?? 0.10);
    }

    public function getLongRunWatchBufferKm(): float
    {
        return (float) ($this->data['long_run_step']['watch_buffer_km'] ?? 0.8);
    }

    public function getTsbLowPercentile(): float
    {
        return (float) ($this->data['cardiovascular']['tsb_low_percentile'] ?? 0.10);
    }

    public function getSubjectiveCautionScore(): int
    {
        return (int) ($this->data['subjective']['caution_score'] ?? 3);
    }

    public function getSubjectiveOverrideScore(): int
    {
        return (int) ($this->data['subjective']['override_score'] ?? 5);
    }

    public function getSubjectiveConsecutiveDaysTrigger(): int
    {
        return (int) ($this->data['subjective']['consecutive_days_trigger'] ?? 3);
    }

    public function getTargetRaceDate(): ?string
    {
        $d = $this->data['race']['target_race_date'] ?? null;

        return is_string($d) && trim($d) !== '' ? trim($d) : null;
    }

    public function getTargetDistanceMeters(): int
    {
        return (int) ($this->data['race']['target_distance_m'] ?? 10000);
    }

    public function getTaperWindowDays(): int
    {
        return (int) ($this->data['race']['taper_window_days'] ?? 14);
    }

    public function getPostRaceRecoveryDays(): int
    {
        return (int) ($this->data['race']['post_race_recovery_days'] ?? 14);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
