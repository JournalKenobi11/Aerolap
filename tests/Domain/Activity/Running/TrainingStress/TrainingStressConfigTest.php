<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity\Running\TrainingStress;

use App\Domain\Activity\Running\TrainingStress\TrainingStressConfig;
use PHPUnit\Framework\TestCase;

final class TrainingStressConfigTest extends TestCase
{
    public function testDefaultConfigValues(): void
    {
        $config = TrainingStressConfig::fromArray([
            'config_version' => '1.0.0',
            'athlete' => [
                'resting_hr' => 55,
                'max_hr' => 194,
                'hr_calc_method' => 'Heart Rate Reserve (HRR)',
            ],
            'windows' => [
                'acute_days' => 7,
                'chronic_prior_days' => 21,
                'long_run_history_days' => 30,
                'insufficient_history_days' => 21,
            ],
            'mechanical_weights' => [
                'run' => 1.0,
                'walk' => 0.25,
                'hike' => 0.40,
                'intensity_weight_z4_z5' => 1.25,
            ],
            'volume_step' => [
                'floor_km' => 2.5,
                'pct' => 0.10,
                'watch_buffer_km' => 2.5,
            ],
            'long_run_step' => [
                'flat_threshold_km' => 1.2,
                'cutoff_distance_km' => 10.0,
                'percentage_above_cutoff' => 0.10,
                'watch_buffer_km' => 0.8,
            ],
            'cardiovascular' => [
                'tsb_low_percentile' => 0.10,
            ],
            'subjective' => [
                'caution_score' => 3,
                'override_score' => 5,
                'consecutive_days_trigger' => 3,
            ],
            'race' => [
                'target_race_date' => '2026-11-22',
                'target_distance_m' => 10000,
                'taper_window_days' => 14,
                'post_race_recovery_days' => 14,
            ],
        ]);

        $this->assertSame('1.0.0', $config->getConfigVersion());
        $this->assertSame(55, $config->getRestingHr());
        $this->assertSame(194, $config->getMaxHr());
        $this->assertSame('Heart Rate Reserve (HRR)', $config->getHrCalcMethod());

        $this->assertSame(7, $config->getAcuteDays());
        $this->assertSame(21, $config->getChronicPriorDays());
        $this->assertSame(30, $config->getLongRunHistoryDays());
        $this->assertSame(21, $config->getInsufficientHistoryDays());

        $this->assertSame(1.0, $config->getRunWeight());
        $this->assertSame(0.25, $config->getWalkWeight());
        $this->assertSame(0.40, $config->getHikeWeight());
        $this->assertSame(1.25, $config->getIntensityWeightZ4Z5());

        $this->assertSame(2.5, $config->getVolumeStepFloorKm());
        $this->assertSame(0.10, $config->getVolumeStepPct());
        $this->assertSame(2.5, $config->getVolumeStepWatchBufferKm());

        $this->assertSame(1.2, $config->getLongRunFlatThresholdKm());
        $this->assertSame(10.0, $config->getLongRunCutoffDistanceKm());
        $this->assertSame(0.10, $config->getLongRunPercentageAboveCutoff());
        $this->assertSame(0.8, $config->getLongRunWatchBufferKm());

        $this->assertSame(0.10, $config->getTsbLowPercentile());

        $this->assertSame(3, $config->getSubjectiveCautionScore());
        $this->assertSame(5, $config->getSubjectiveOverrideScore());
        $this->assertSame(3, $config->getSubjectiveConsecutiveDaysTrigger());

        $this->assertSame('2026-11-22', $config->getTargetRaceDate());
        $this->assertSame(10000, $config->getTargetDistanceMeters());
        $this->assertSame(14, $config->getTaperWindowDays());
        $this->assertSame(14, $config->getPostRaceRecoveryDays());
    }

    public function testLoadFromConfigFile(): void
    {
        $configFile = dirname(__DIR__, 5) . '/config/app/training_stress_config.json';
        $this->assertFileExists($configFile);

        $config = TrainingStressConfig::loadFromFile($configFile);
        $this->assertSame('1.0.0', $config->getConfigVersion());
        $this->assertSame(55, $config->getRestingHr());
        $this->assertSame(194, $config->getMaxHr());
        $this->assertSame('2026-11-22', $config->getTargetRaceDate());
        $this->assertSame(2.5, $config->getVolumeStepFloorKm());
    }
}
