<?php

declare(strict_types=1);

namespace App\Domain\Integration\AI\Tool;

use Doctrine\DBAL\Connection;
use NeuronAI\Tools\Tool;

final class GetRunningTrends extends Tool
{
    #[\Override]
    protected string $name = 'get_running_trends';

    #[\Override]
    protected ?string $description = <<<DESC
        Retrieves weekly (last 12 weeks) and monthly (last 12 months) aggregated running statistics,
        including total distance (km), total time, average pace, average heart rate, and number of runs.
        Use this tool to analyze training volume, aerobic consistency, weekly mileage buildup, or fatigue.
        DESC;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        // Monthly trends
        $monthlySql = <<<SQL
            SELECT 
                strftime('%Y-%m', startDateTime) AS month,
                count(*) AS run_count,
                sum(distance) AS total_distance_m,
                sum(movingTimeInSeconds) AS total_moving_time_s,
                avg(averageHeartRate) AS avg_hr
            FROM Activity
            WHERE sportType = 'Run'
            GROUP BY month
            ORDER BY month DESC
            LIMIT 12
        SQL;

        $monthlyRows = $this->connection->executeQuery($monthlySql)->fetchAllAssociative();
        $monthly = array_map(function (array $m): array {
            $distM = (float) ($m['total_distance_m'] ?? 0);
            $timeS = (int) ($m['total_moving_time_s'] ?? 0);
            $distKm = $distM > 0 ? round($distM / 1000.0, 2) : 0.0;

            $pace = null;
            if ($distKm > 0 && $timeS > 0) {
                $secPerKm = $timeS / $distKm;
                $mins = (int) floor($secPerKm / 60);
                $secs = (int) round(fmod($secPerKm, 60));
                if ($secs >= 60) {
                    $mins++;
                    $secs = 0;
                }
                $pace = sprintf('%d:%02d /km', $mins, $secs);
            }

            return [
                'month' => $m['month'],
                'run_count' => (int) $m['run_count'],
                'total_distance_km' => $distKm,
                'total_time_hours' => round($timeS / 3600.0, 1),
                'average_pace' => $pace,
                'avg_heart_rate' => $m['avg_hr'] !== null ? round((float) $m['avg_hr'], 1) : null,
            ];
        }, $monthlyRows);

        // Weekly trends
        $weeklySql = <<<SQL
            SELECT 
                strftime('%Y-W%W', startDateTime) AS week,
                min(startDateTime) AS week_start,
                count(*) AS run_count,
                sum(distance) AS total_distance_m,
                sum(movingTimeInSeconds) AS total_moving_time_s,
                avg(averageHeartRate) AS avg_hr
            FROM Activity
            WHERE sportType = 'Run'
            GROUP BY week
            ORDER BY week DESC
            LIMIT 12
        SQL;

        $weeklyRows = $this->connection->executeQuery($weeklySql)->fetchAllAssociative();
        $weekly = array_map(function (array $w): array {
            $distM = (float) ($w['total_distance_m'] ?? 0);
            $timeS = (int) ($w['total_moving_time_s'] ?? 0);
            $distKm = $distM > 0 ? round($distM / 1000.0, 2) : 0.0;

            $pace = null;
            if ($distKm > 0 && $timeS > 0) {
                $secPerKm = $timeS / $distKm;
                $mins = (int) floor($secPerKm / 60);
                $secs = (int) round(fmod($secPerKm, 60));
                if ($secs >= 60) {
                    $mins++;
                    $secs = 0;
                }
                $pace = sprintf('%d:%02d /km', $mins, $secs);
            }

            return [
                'week' => $w['week'],
                'approx_start' => substr((string) $w['week_start'], 0, 10),
                'run_count' => (int) $w['run_count'],
                'total_distance_km' => $distKm,
                'total_time_hours' => round($timeS / 3600.0, 1),
                'average_pace' => $pace,
                'avg_heart_rate' => $w['avg_hr'] !== null ? round((float) $w['avg_hr'], 1) : null,
            ];
        }, $weeklyRows);

        return [
            'monthlyTrends' => $monthly,
            'weeklyTrends' => $weekly,
        ];
    }
}
