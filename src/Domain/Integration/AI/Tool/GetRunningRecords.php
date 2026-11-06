<?php

declare(strict_types=1);

namespace App\Domain\Integration\AI\Tool;

use Doctrine\DBAL\Connection;
use NeuronAI\Tools\Tool;

final class GetRunningRecords extends Tool
{
    private const array STANDARD_DISTANCES = [
        400 => '400m',
        804 => '1/2 Mile (804m)',
        1000 => '1 km',
        1609 => '1 Mile (1609m)',
        3218 => '2 Miles (3218m)',
        5000 => '5 km',
        10000 => '10 km',
        15000 => '15 km',
        21097 => 'Half Marathon (21.1 km)',
        42195 => 'Marathon (42.2 km)',
    ];

    #[\Override]
    protected string $name = 'get_running_records';

    #[\Override]
    protected ?string $description = <<<DESC
        Retrieves the athlete's all-time personal records (PRs) across standard distances (400m, 800m, 1k, 1 mile, 2 miles, 5k, 10k, 15k).
        Use this tool when the user asks about their fastest times, personal bests, benchmark paces, or PR history.
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
        $sql = <<<SQL
            SELECT 
                b.distanceInMeter,
                b.timeInSeconds,
                a.name AS activityName,
                a.startDateTime AS activityDate,
                a.activityId
            FROM ActivityBestEffort b
            JOIN Activity a ON b.activityId = a.activityId
            WHERE b.sportType = 'Run'
            ORDER BY b.distanceInMeter ASC, b.timeInSeconds ASC
        SQL;

        $rows = $this->connection->executeQuery($sql)->fetchAllAssociative();

        $seen = [];
        $records = [];
        foreach ($rows as $r) {
            $distM = (int) $r['distanceInMeter'];
            if (isset($seen[$distM])) {
                continue;
            }
            $seen[$distM] = true;

            $timeS = (int) $r['timeInSeconds'];
            $km = $distM / 1000.0;
            $pace = null;
            if ($km > 0 && $timeS > 0) {
                $secPerKm = $timeS / $km;
                $mins = (int) floor($secPerKm / 60);
                $secs = (int) round(fmod($secPerKm, 60));
                if ($secs >= 60) {
                    $mins++;
                    $secs = 0;
                }
                $pace = sprintf('%d:%02d /km', $mins, $secs);
            }

            $formattedTime = null;
            if ($timeS >= 3600) {
                $h = (int) floor($timeS / 3600);
                $rem = $timeS % 3600;
                $formattedTime = sprintf('%dh %02dm %02ds', $h, (int) floor($rem / 60), $rem % 60);
            } else {
                $formattedTime = sprintf('%d:%02d', (int) floor($timeS / 60), $timeS % 60);
            }

            $activityId = (string) $r['activityId'];
            if (str_starts_with($activityId, 'activity-')) {
                $activityId = substr($activityId, 9);
            }

            $records[] = [
                'distance' => self::STANDARD_DISTANCES[$distM] ?? ($distM . 'm'),
                'distance_meters' => $distM,
                'time' => $formattedTime,
                'pace' => $pace,
                'date' => $r['activityDate'],
                'activity_name' => $r['activityName'],
                'activity_id' => $activityId,
            ];
        }

        return ['personalRecords' => $records];
    }
}
