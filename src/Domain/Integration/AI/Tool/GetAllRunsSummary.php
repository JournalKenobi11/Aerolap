<?php

declare(strict_types=1);

namespace App\Domain\Integration\AI\Tool;

use Doctrine\DBAL\Connection;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

final class GetAllRunsSummary extends Tool
{
    #[\Override]
    protected string $name = 'get_all_runs';

    #[\Override]
    protected ?string $description = <<<DESC
        Retrieves all running workouts (sportType = 'Run') recorded by the athlete, sorted newest to oldest.
        Includes date, name, distance (km), duration, average pace (min:sec/km), average heart rate, max heart rate, cadence, and elevation gain.
        Use this tool whenever the user asks for running analysis, overall running performance, pacing trends, 5k progression, workout history, or volume comparisons.
        DESC;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    #[\Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Maximum number of runs to return (default 100, max 250).',
                required: false
            ),
            new ToolProperty(
                name: 'fromDate',
                type: PropertyType::STRING,
                description: 'Optional start date filter in YYYY-MM-DD format.',
                required: false
            ),
            new ToolProperty(
                name: 'toDate',
                type: PropertyType::STRING,
                description: 'Optional end date filter in YYYY-MM-DD format.',
                required: false
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $limit = 100, ?string $fromDate = null, ?string $toDate = null): array
    {
        $limit = max(1, min(250, $limit ?? 100));

        $sql = <<<SQL
            SELECT 
                activityId, name, startDateTime, distance, movingTimeInSeconds,
                averageSpeed, averageHeartRate, maxHeartRate, averageCadence, elevation
            FROM Activity
            WHERE sportType = 'Run'
        SQL;

        $params = [];
        if ($fromDate) {
            $sql .= ' AND startDateTime >= :fromDate';
            $params['fromDate'] = $fromDate . ' 00:00:00';
        }
        if ($toDate) {
            $sql .= ' AND startDateTime <= :toDate';
            $params['toDate'] = $toDate . ' 23:59:59';
        }

        $sql .= ' ORDER BY startDateTime DESC LIMIT ' . (int) $limit;

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        $runs = array_map(function (array $r): array {
            $distM = (float) ($r['distance'] ?? 0);
            $movingS = (int) ($r['movingTimeInSeconds'] ?? 0);
            $distKm = $distM > 0 ? round($distM / 1000.0, 2) : 0.0;

            $pace = null;
            if ($distKm > 0 && $movingS > 0) {
                $secPerKm = $movingS / $distKm;
                $mins = (int) floor($secPerKm / 60);
                $secs = (int) round(fmod($secPerKm, 60));
                if ($secs >= 60) {
                    $mins++;
                    $secs = 0;
                }
                $pace = sprintf('%d:%02d /km', $mins, $secs);
            }

            $duration = null;
            if ($movingS >= 3600) {
                $hours = (int) floor($movingS / 3600);
                $rem = $movingS % 3600;
                $duration = sprintf('%dh %02dm %02ds', $hours, (int) floor($rem / 60), $rem % 60);
            } elseif ($movingS > 0) {
                $duration = sprintf('%d:%02d', (int) floor($movingS / 60), $movingS % 60);
            }

            $activityId = (string) $r['activityId'];
            if (str_starts_with($activityId, 'activity-')) {
                $activityId = substr($activityId, 9);
            }

            return [
                'id' => $activityId,
                'date' => $r['startDateTime'],
                'name' => $r['name'],
                'distance_km' => $distKm,
                'duration' => $duration,
                'pace' => $pace,
                'avg_hr' => $r['averageHeartRate'] !== null ? (int) $r['averageHeartRate'] : null,
                'max_hr' => $r['maxHeartRate'] !== null ? (int) $r['maxHeartRate'] : null,
                'avg_speed_kmh' => $r['averageSpeed'] !== null ? round((float) $r['averageSpeed'], 2) : null,
                'cadence' => $r['averageCadence'] !== null ? (int) $r['averageCadence'] : null,
                'elevation_m' => $r['elevation'] !== null ? (int) $r['elevation'] : null,
            ];
        }, $rows);

        return [
            'totalRunsReturned' => count($runs),
            'runs' => $runs,
        ];
    }
}
