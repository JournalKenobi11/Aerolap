<?php

declare(strict_types=1);

namespace App\Domain\Integration\AI\Tool;

use App\Domain\Activity\Running\RaceTimePredictor;
use Doctrine\DBAL\Connection;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

final class GetRacePredictions extends Tool
{
    #[\Override]
    protected string $name = 'get_race_predictions';

    #[\Override]
    protected ?string $description = <<<DESC
        Calculates predicted finish times and target paces for standard running race distances: 5K, 10K, Half Marathon, and Marathon.
        Uses Pete Riegel's endurance power model (T2 = T1 * (D2/D1)^1.06) based on recent credible performances, providing uncertainty intervals (+/- 3-10%) and confidence ratings.
        Use this tool when the athlete asks: "What is my predicted 5K/10K/marathon time?", "Can I run a sub-2 hour half marathon?", or for race pacing guidance.
        DESC;

    public function __construct(
        private readonly Connection $connection,
        private readonly RaceTimePredictor $raceTimePredictor = new RaceTimePredictor(),
    ) {
    }

    #[\Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'customDistanceMeters',
                type: PropertyType::INTEGER,
                description: 'Optional custom race distance in meters (e.g. 15000 for 15k). If omitted, standard 5k, 10k, Half, and Marathon predictions are returned.',
                required: false
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $customDistanceMeters = null): array
    {
        $rows = $this->connection->executeQuery(
            <<<SQL
                SELECT be.activityId, a.name AS activityName, a.startDateTime AS date,
                       be.distanceInMeter, be.timeInSeconds
                FROM ActivityBestEffort be
                INNER JOIN Activity a ON be.activityId = a.activityId
                WHERE be.sportType = 'Run'
                  AND be.distanceInMeter >= 800
                ORDER BY a.startDateTime DESC
            SQL
        )->fetchAllAssociative();

        if ([] === $rows) {
            return [
                'status' => 'insufficient_data',
                'message' => 'No recorded best efforts found. Import more running activities to calculate race predictions.',
            ];
        }

        $performances = [];
        foreach ($rows as $r) {
            $performances[] = [
                'activityId' => (string) $r['activityId'],
                'activityName' => (string) $r['activityName'],
                'date' => (string) $r['date'],
                'distanceMeters' => (int) $r['distanceInMeter'],
                'timeSeconds' => (int) $r['timeInSeconds'],
            ];
        }

        $standardPredictions = $this->raceTimePredictor->predictAll($performances);

        $results = [];
        foreach ($standardPredictions as $pred) {
            $results[] = [
                'target' => $pred->getTargetLabel(),
                'distance_meters' => $pred->getTargetDistanceMeters(),
                'predicted_time' => $pred->getFormattedPredictedTime(),
                'predicted_pace' => $pred->getPredictedPace(),
                'uncertainty_range' => $pred->getFormattedRange(),
                'confidence' => $pred->getConfidenceLevel(),
                'reference_performance' => [
                    'activity_name' => $pred->getSourceActivityName(),
                    'date' => $pred->getSourceDate(),
                    'distance' => $pred->getFormattedSourceDistance(),
                    'time' => $pred->getFormattedSourceTime(),
                ],
                'notes' => $pred->getNotes(),
            ];
        }

        $customPrediction = null;
        if (null !== $customDistanceMeters && $customDistanceMeters > 0) {
            $p = $this->raceTimePredictor->predictForTarget($customDistanceMeters, sprintf('%.1f km', $customDistanceMeters / 1000.0), $performances);
            if (null !== $p) {
                $customPrediction = [
                    'target' => $p->getTargetLabel(),
                    'predicted_time' => $p->getFormattedPredictedTime(),
                    'predicted_pace' => $p->getPredictedPace(),
                    'uncertainty_range' => $p->getFormattedRange(),
                    'confidence' => $p->getConfidenceLevel(),
                    'reference_performance' => [
                        'activity_name' => $p->getSourceActivityName(),
                        'date' => $p->getSourceDate(),
                        'distance' => $p->getFormattedSourceDistance(),
                        'time' => $p->getFormattedSourceTime(),
                    ],
                ];
            }
        }

        return [
            'model' => 'Pete Riegel Power Formula (k=1.06)',
            'predictions' => $results,
            'custom_prediction' => $customPrediction,
            'coaching_caveat' => 'Predictions assume specific training preparation for the target distance. Half and Full marathon finish times require adequate weekly volume and progressive long runs.',
        ];
    }
}
