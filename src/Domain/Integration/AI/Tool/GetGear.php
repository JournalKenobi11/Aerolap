<?php

declare(strict_types=1);

namespace App\Domain\Integration\AI\Tool;

use App\Domain\Gear\Gear;
use App\Domain\Gear\GearRepository;
use Doctrine\DBAL\Connection;
use NeuronAI\Tools\Tool;

final class GetGear extends Tool
{
    #[\Override]
    protected string $name = 'get_gear';

    #[\Override]
    protected ?string $description = <<<DESC
        Retrieves the athlete’s gear (running shoes, bikes, equipment) from the database with total mileage AND exact sport-type breakdown (e.g. kilometers from Running vs Walking vs Hiking).
        Use this tool whenever the athlete asks about gear, shoes, shoe mileage, shoe wear and tear, or the breakdown between walking and running on their shoes.
        DESC;

    public function __construct(
        private readonly GearRepository $gearRepository,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<int, mixed>
     */
    public function __invoke(): array
    {
        $gears = $this->gearRepository->findAllUsed();

        return $gears->map(function (Gear $gear): array {
            $gearId = (string) $gear->getId();

            $rows = $this->connection->executeQuery(
                <<<SQL
                    SELECT 
                        sportType, 
                        COUNT(*) as activityCount, 
                        SUM(distance) as totalDistanceInMeters,
                        SUM(movingTimeInSeconds) as totalDurationInSeconds,
                        MIN(startDateTime) as firstUsed,
                        MAX(startDateTime) as lastUsed
                    FROM Activity
                    WHERE gearId = :gearId
                    GROUP BY sportType
                    ORDER BY totalDistanceInMeters DESC
                SQL,
                ['gearId' => $gearId]
            )->fetchAllAssociative();

            $totalMeters = array_sum(array_column($rows, 'totalDistanceInMeters'));
            $breakdown = [];
            foreach ($rows as $r) {
                $sportMeters = (float) $r['totalDistanceInMeters'];
                $sportSeconds = (int) $r['totalDurationInSeconds'];
                $hours = (int) floor($sportSeconds / 3600);
                $mins = (int) floor(($sportSeconds % 3600) / 60);

                $breakdown[$r['sportType']] = [
                    'activity_count' => (int) $r['activityCount'],
                    'distance_km' => round($sportMeters / 1000.0, 2),
                    'moving_time' => sprintf('%dh %02dm', $hours, $mins),
                    'percentage_of_total' => $totalMeters > 0 ? round(($sportMeters / $totalMeters) * 100, 1) . '%' : '0%',
                    'first_used' => substr((string) $r['firstUsed'], 0, 10),
                    'last_used' => substr((string) $r['lastUsed'], 0, 10),
                ];
            }

            return [
                'id' => $gear->getId()->toUnprefixedString(),
                'name' => $gear->getName(),
                'totalDistanceInKilometer' => $gear->getDistance(),
                'isRetired' => $gear->isRetired(),
                'usage_breakdown_by_sport' => $breakdown,
            ];
        });
    }
}
