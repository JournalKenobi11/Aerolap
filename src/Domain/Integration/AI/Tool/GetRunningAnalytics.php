<?php

declare(strict_types=1);

namespace App\Domain\Integration\AI\Tool;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\Running\RunningAnalyticsService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

final class GetRunningAnalytics extends Tool
{
    #[\Override]
    protected string $name = 'get_running_analytics';

    #[\Override]
    protected ?string $description = <<<DESC
        Provides advanced running physiology analytics comparable to Garmin Connect / TrainingPeaks.
        Returns:
        1. Overall Athlete Training Status (Fitness CTL, Fatigue ATL, Form TSB, Acute-to-Chronic Ratio ACWR, Monotony, Strain, and Plain-Language Workout Guidance).
        2. Per-Activity Running Analytics (if activityId provided):
           - Time-weighted 5 Pace Zones distribution (Recovery, Aerobic, Tempo, Threshold, Anaerobic) with distance and percentage.
           - Aerobic Decoupling (Pa:Hr / Pw:Hr) & Cardiac Drift comparing 1st half vs 2nd half of steady runs with Joe Friel efficiency assessment.
           - Session Relative Effort score (0-120+) and recommended recovery hours.
           - Stream data integrity and sensor coverage telemetry.
        DESC;

    public function __construct(
        private readonly RunningAnalyticsService $runningAnalyticsService,
    ) {
    }

    #[\Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'activityId',
                type: PropertyType::STRING,
                description: 'Optional activity ID (e.g. activity-c314c4be-...). If provided, includes per-run pace zones, aerobic decoupling, and effort scoring.',
                required: false
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $activityId = null): array
    {
        // 1. Always include training status & readiness guidance
        $trainingStatus = $this->runningAnalyticsService->getTrainingStatus();
        $statusData = [
            'status' => $trainingStatus->getStatus(),
            'headline' => $trainingStatus->getHeadline(),
            'guidance' => $trainingStatus->getNextWorkoutGuidance(),
            'explanation' => $trainingStatus->getPlainLanguageExplanation(),
            'metrics' => [
                'ctl_fitness' => $trainingStatus->getCtl(),
                'atl_fatigue' => $trainingStatus->getAtl(),
                'tsb_form' => $trainingStatus->getTsb(),
                'acwr_ratio' => $trainingStatus->getAcRatio(),
                'monotony' => $trainingStatus->getMonotony(),
                'strain' => $trainingStatus->getStrain(),
            ],
        ];

        $activityData = null;
        if (null !== $activityId && '' !== trim($activityId)) {
            $actId = ActivityId::fromPrefixedOrUnprefixed(trim($activityId));
            $analysis = $this->runningAnalyticsService->analyzeRunActivity($actId);

            $paceZones = null;
            if (null !== $analysis['paceZones']) {
                $paceZones = [
                    'total_moving_time_seconds' => $analysis['paceZones']->getTotalMovingTimeInSeconds(),
                    'total_moving_distance_km' => $analysis['paceZones']->getTotalMovingDistance()->toFloat(),
                    'excluded_paused_percent' => $analysis['paceZones']->getExcludedPercentage(),
                    'zones' => array_map(static fn (array $row): array => [
                        'zone' => $row['name'],
                        'label' => $row['label'],
                        'pace_range' => $row['range'],
                        'duration' => $row['formattedTime'],
                        'percentage' => $row['percentage'],
                        'distance_meters' => round($row['distanceMeters']),
                    ], $analysis['paceZones']->toViewRows()),
                ];
            }

            $decoupling = null;
            if (null !== $analysis['decoupling']) {
                $d = $analysis['decoupling'];
                if ($d->isEligible()) {
                    $decoupling = [
                        'eligible' => true,
                        'decoupling_percentage' => $d->getDecouplingPercentage(),
                        'heart_rate_drift_bpm' => $d->getHeartRateDriftBpm(),
                        'pace_drift_sec_per_km' => $d->getPaceDriftSecPerKm(),
                        'assessment' => $d->getAssessment(),
                        'first_half' => [
                            'avg_pace' => $d->getFormattedPace($d->getHalfOneAvgPace()),
                            'avg_hr' => $d->getHalfOneAvgHeartRate(),
                            'efficiency_factor' => $d->getHalfOneEfficiencyFactor(),
                        ],
                        'second_half' => [
                            'avg_pace' => $d->getFormattedPace($d->getHalfTwoAvgPace()),
                            'avg_hr' => $d->getHalfTwoAvgHeartRate(),
                            'efficiency_factor' => $d->getHalfTwoEfficiencyFactor(),
                        ],
                    ];
                } else {
                    $decoupling = [
                        'eligible' => false,
                        'ineligibility_reason' => $d->getIneligibilityReason(),
                    ];
                }
            }

            $effort = null;
            if (null !== $analysis['effortScore']) {
                $e = $analysis['effortScore'];
                $effort = [
                    'relative_effort_score' => $e->getScore(),
                    'classification' => $e->getLabel(),
                    'description' => $e->getDescription(),
                    'recommended_recovery_hours' => $e->getEstimatedRecoveryHours(),
                    'recovery_advice' => $e->getRecoveryAdvice(),
                ];
            }

            $activityData = [
                'activity_id' => $activityId,
                'pace_zones' => $paceZones,
                'aerobic_decoupling' => $decoupling,
                'session_effort' => $effort,
                'data_quality' => $analysis['dataQuality'],
            ];
        }

        return [
            'athlete_training_status' => $statusData,
            'activity_analysis' => $activityData,
        ];
    }
}
