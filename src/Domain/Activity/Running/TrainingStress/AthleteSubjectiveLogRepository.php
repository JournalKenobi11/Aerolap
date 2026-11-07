<?php

declare(strict_types=1);

namespace App\Domain\Activity\Running\TrainingStress;

use Doctrine\DBAL\Connection;

final readonly class AthleteSubjectiveLogRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function record(string $date, int $sorenessScore, string $location, ?string $notes = null): void
    {
        $logId = 'subj-' . bin2hex(random_bytes(8));
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Delete existing log for this date if present, keeping latest for the day
        $this->connection->executeStatement(
            'DELETE FROM AthleteSubjectiveLog WHERE date = :date',
            ['date' => $date]
        );

        $this->connection->insert('AthleteSubjectiveLog', [
            'logId' => $logId,
            'date' => $date,
            'sorenessScore' => max(0, min(10, $sorenessScore)),
            'location' => $location,
            'notes' => $notes,
            'createdOn' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLatest(): ?array
    {
        $row = $this->connection->executeQuery(
            'SELECT * FROM AthleteSubjectiveLog ORDER BY date DESC, createdOn DESC LIMIT 1'
        )->fetchAssociative();

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findRecent(int $days = 14): array
    {
        return $this->connection->executeQuery(
            'SELECT * FROM AthleteSubjectiveLog ORDER BY date DESC, createdOn DESC LIMIT ' . (int) $days
        )->fetchAllAssociative();
    }

    /**
     * Checks if soreness has been >= $threshold for $consecutiveDays in a row.
     */
    public function hasConsecutiveSoreness(int $threshold = 3, int $consecutiveDays = 3): bool
    {
        $logs = $this->connection->executeQuery(
            'SELECT date, sorenessScore FROM AthleteSubjectiveLog ORDER BY date DESC LIMIT ' . (int) $consecutiveDays
        )->fetchAllAssociative();

        if (count($logs) < $consecutiveDays) {
            return false;
        }

        foreach ($logs as $log) {
            if ((int) $log['sorenessScore'] < $threshold) {
                return false;
            }
        }

        return true;
    }
}
