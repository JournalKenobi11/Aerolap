<?php

declare(strict_types=1);

namespace App\Domain\Activity\Running;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\BestEffort\ActivityBestEffortRepository;
use App\Domain\Activity\DailyTrainingLoad;
use App\Domain\Activity\EnrichedActivityRepository;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\Stream\ActivityHeartRateRepository;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Athlete\PaceZone\PaceZone;
use App\Domain\Athlete\PaceZone\PaceZones;
use App\Domain\Athlete\PaceZone\TimeInPaceZones;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingMetrics;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class RunningAnalyticsService
{
    public function __construct(
        private ActivityRepository $activityRepository,
        private ActivityStreamRepository $activityStreamRepository,
        private ActivityHeartRateRepository $activityHeartRateRepository,
        private ActivityBestEffortRepository $activityBestEffortRepository,
        private EnrichedActivityRepository $enrichedActivityRepository,
        private SettingsRepository $settingsRepository,
        private DailyTrainingLoad $dailyTrainingLoad,
        private Clock $clock,
        private Connection $connection,
        private PaceZoneCalculator $paceZoneCalculator = new PaceZoneCalculator(),
        private AerobicDecouplingCalculator $aerobicDecouplingCalculator = new AerobicDecouplingCalculator(),
        private RaceTimePredictor $raceTimePredictor = new RaceTimePredictor(),
        private RunningStreamValidator $streamValidator = new RunningStreamValidator(),
    ) {
    }

    /**
     * @return array{
     *     paceZones: TimeInPaceZones|null,
     *     decoupling: AerobicDecouplingResult|null,
     *     effortScore: RunningEffortScore|null,
     *     dataQuality: array<string, mixed>|null,
     * }
     */
    public function analyzeRunActivity(ActivityId $activityId): array
    {
        $activity = $this->activityRepository->find($activityId);
        if (SportType::RUN !== $activity->getSportType()) {
            return [
                'paceZones' => null,
                'decoupling' => null,
                'effortScore' => null,
                'dataQuality' => null,
            ];
        }

        // 1. Fetch streams
        $timeStream = $this->getStreamData($activityId, StreamType::TIME);
        $velocityStream = $this->getStreamData($activityId, StreamType::VELOCITY);
        $heartRateStream = $this->getStreamData($activityId, StreamType::HEART_RATE);
        $distanceStream = $this->getStreamData($activityId, StreamType::DISTANCE);
        $altitudeStream = $this->getStreamData($activityId, StreamType::ALTITUDE);
        $movingStream = $this->getStreamData($activityId, StreamType::MOVING);
        $cadenceStream = $this->getStreamData($activityId, StreamType::CADENCE);

        // 2. Pace Zones (derived from athlete's actual threshold pace)
        $paceZones = $this->resolvePaceZones();
        $timeInPaceZones = null;
        if ([] !== $timeStream && [] !== $velocityStream) {
            $timeInPaceZones = $this->paceZoneCalculator->calculate(
                paceZones: $paceZones,
                timeStream: $timeStream,
                velocityStream: $velocityStream,
                movingStream: $movingStream ?: null,
                distanceStream: $distanceStream ?: null,
            );
        }

        // 3. Aerobic Decoupling & Cardiac Drift
        $decouplingResult = null;
        if ([] !== $timeStream && [] !== $velocityStream && [] !== $heartRateStream) {
            $decouplingResult = $this->aerobicDecouplingCalculator->calculate(
                timeStream: $timeStream,
                velocityStream: $velocityStream,
                heartRateStream: $heartRateStream,
                altitudeStream: $altitudeStream ?: null,
                movingStream: $movingStream ?: null,
                distanceStream: $distanceStream ?: null,
            );
        }

        // 4. Session Relative Effort & Recovery Score
        $effortScore = null;
        try {
            $hrZones = $this->activityHeartRateRepository->findTotalTimeInSecondsInHeartRateZonesForActivity($activityId);
            $effortScore = RunningEffortScore::fromHeartRateZones($hrZones, $activity->getMovingTimeInSeconds());
        } catch (EntityNotFound) {
            $effortScore = RunningEffortScore::fromHeartRateZones(null, $activity->getMovingTimeInSeconds());
        }

        // 5. Data Quality Validation
        $dataQuality = null;
        if ([] !== $timeStream && [] !== $velocityStream) {
            $dataQuality = $this->streamValidator->validate(
                timeStream: $timeStream,
                velocityStream: $velocityStream,
                heartRateStream: $heartRateStream ?: null,
                distanceStream: $distanceStream ?: null,
                altitudeStream: $altitudeStream ?: null,
                cadenceStream: $cadenceStream ?: null,
            );
        }

        return [
            'paceZones' => $timeInPaceZones,
            'decoupling' => $decouplingResult,
            'effortScore' => $effortScore,
            'dataQuality' => $dataQuality,
        ];
    }

    /**
     * Get race predictions for standard distances (5k, 10k, Half, Marathon).
     *
     * @return RacePrediction[]
     */
    public function getRacePredictions(): array
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

        $performances = [];
        foreach ($rows as $row) {
            $performances[] = [
                'activityId' => (string) $row['activityId'],
                'activityName' => (string) $row['activityName'],
                'date' => (string) $row['date'],
                'distanceMeters' => (int) $row['distanceInMeter'],
                'timeSeconds' => (int) $row['timeInSeconds'],
            ];
        }

        return $this->raceTimePredictor->predictAll($performances);
    }

    /**
     * Get overall training status interpretation with plain-language advice.
     */
    public function getTrainingStatus(): RunningTrainingStatusAdvisor
    {
        $now = $this->clock->getCurrentDateTimeImmutable();
        $dateRange = DateRange::lastXDays($now, 90);
        $loadPerDay = $this->dailyTrainingLoad->calculateForDateRange($dateRange);
        $trainingMetrics = TrainingMetrics::create($loadPerDay);

        $ctl = $trainingMetrics->getCurrentCtl() ?? 0.0;
        $atl = $trainingMetrics->getCurrentAtl() ?? 0.0;
        $tsb = $trainingMetrics->getCurrentTsb()?->getValue() ?? 0.0;
        $acRatio = $trainingMetrics->getCurrentAcRatio()?->getValue() ?? 0.0;
        $monotony = $trainingMetrics->getCurrentMonotony();
        $strain = $trainingMetrics->getCurrentStrain();

        $ctl7DaysAgo = $trainingMetrics->getCtlValuesForXLastDays(8)[0] ?? $ctl;
        $ctlChange7Days = round($ctl - $ctl7DaysAgo, 1);

        return RunningTrainingStatusAdvisor::analyze(
            ctl: $ctl,
            atl: $atl,
            tsb: $tsb,
            acRatio: $acRatio,
            monotony: $monotony,
            strain: $strain,
            ctlChangeLast7Days: $ctlChange7Days
        );
    }

    public function resolvePaceZones(): PaceZones
    {
        $telemetryZones = $this->detectPaceZonesFromHeartRateTelemetry();
        if ($telemetryZones instanceof PaceZones) {
            return $telemetryZones;
        }

        $thresholdSec = $this->autoDetectThresholdPace();
        if ($thresholdSec > 0) {
            return PaceZones::fromThresholdPace($thresholdSec);
        }

        return PaceZones::default();
    }

    public function detectPaceZonesFromHeartRateTelemetry(): ?PaceZones
    {
        $general = $this->settingsRepository->general();
        $athlete = $general->getAthlete();
        $now = $this->clock->getCurrentDateTimeImmutable();
        $maxHr = $athlete->getMaxHeartRate($now);
        $hrZones = $general->getHeartRateZoneConfiguration()->getDefaultHeartRateZones(SportType::RUN);

        $z2CeilingHr = $hrZones->getZoneTwo()->getRangeInBpm($maxHr)[1] ?? 136;
        $z3CeilingHr = $hrZones->getZoneThree()->getRangeInBpm($maxHr)[1] ?? 156;
        $z4CeilingHr = $hrZones->getZoneFour()->getRangeInBpm($maxHr)[1] ?? 175;

        /** @var list<string> $activityIds */
        $activityIds = $this->connection->executeQuery(
            <<<SQL
                SELECT a.activityId
                FROM Activity a
                WHERE a.sportType = 'Run'
                  AND EXISTS (SELECT 1 FROM ActivityStream s WHERE s.activityId = a.activityId AND s.streamType = 'heartrate')
                  AND EXISTS (SELECT 1 FROM ActivityStream s WHERE s.activityId = a.activityId AND s.streamType = 'velocity_smooth')
                ORDER BY a.startDateTime DESC
                LIMIT 15
            SQL
        )->fetchFirstColumn();

        if (count($activityIds) < 2) {
            return null;
        }

        $rows = $this->connection->executeQuery(
            <<<SQL
                SELECT activityId, streamType, data
                FROM ActivityStream
                WHERE activityId IN (?)
                  AND streamType IN ('heartrate', 'velocity_smooth')
            SQL,
            [$activityIds],
            [ArrayParameterType::STRING]
        )->fetchAllAssociative();

        $streamsByActivity = [];
        foreach ($rows as $row) {
            $streamsByActivity[$row['activityId']][$row['streamType']] = Json::uncompressAndDecode($row['data']);
        }

        $b23Paces = [];
        $b34Paces = [];
        $b45Paces = [];

        foreach ($activityIds as $id) {
            $streams = $streamsByActivity[$id] ?? null;
            if (null === $streams || !isset($streams['heartrate'], $streams['velocity_smooth'])) {
                continue;
            }

            $hr = $streams['heartrate'];
            $vel = $streams['velocity_smooth'];
            $len = min(count($hr), count($vel));
            if ($len < 200) {
                continue;
            }

            // Exclude warm-up (first 180s) and late-run cardiac drift (> 1800s / 30m)
            $maxT = min($len, 1800);
            $w = 15;
            for ($i = 180; $i < $maxT - $w; $i += 3) {
                $vSub = array_slice($vel, $i, $w);
                $hSub = array_slice($hr, $i, $w);

                $valid = true;
                foreach ($vSub as $v) {
                    if (null === $v || $v < 1.4) {
                        $valid = false;
                        break;
                    }
                }
                foreach ($hSub as $h) {
                    if (null === $h || $h < 90) {
                        $valid = false;
                        break;
                    }
                }
                if (!$valid) {
                    continue;
                }

                $avgV = array_sum($vSub) / $w;
                $avgH = array_sum($hSub) / $w;

                // Speed stability filter
                $varV = 0.0;
                foreach ($vSub as $v) {
                    $varV += ($v - $avgV) ** 2;
                }
                if (sqrt($varV / $w) > 0.28) {
                    continue;
                }

                $paceSec = 1000.0 / $avgV;
                if ($paceSec < 150.0 || $paceSec > 720.0) {
                    continue;
                }

                if (abs($avgH - $z2CeilingHr) <= 3.0) {
                    $b23Paces[] = $paceSec;
                }
                if (abs($avgH - $z3CeilingHr) <= 3.0) {
                    $b34Paces[] = $paceSec;
                }
                if (abs($avgH - $z4CeilingHr) <= 4.0) {
                    $b45Paces[] = $paceSec;
                }
            }
        }

        if (count($b34Paces) < 15) {
            return null;
        }

        $calcPercentile = static function (array $arr, float $p): float {
            sort($arr);
            $count = count($arr);
            $idx = ($p / 100.0) * ($count - 1);
            $l = (int) floor($idx);
            $h = (int) ceil($idx);
            if ($l === $h) {
                return (float) $arr[$l];
            }

            return (float) ($arr[$l] + ($idx - $l) * ($arr[$h] - $arr[$l]));
        };

        // Zone 3 ceiling / Zone 4 floor (Tempo -> Threshold): P25 represents fresh steady threshold boundary (~6:25 /km)
        $z3MinPace = (int) round($calcPercentile($b34Paces, 25));

        // Zone 2 ceiling / Zone 3 floor (Aerobic Base -> Tempo): median steady base pace (~7:30 - 7:45 /km)
        $z2MinPace = !empty($b23Paces) ? (int) round($calcPercentile($b23Paces, 50)) : (int) round($z3MinPace * 1.15);
        if ($z2MinPace <= $z3MinPace + 20) {
            $z2MinPace = (int) round($z3MinPace * 1.15);
        }

        // Zone 4 ceiling / Zone 5 floor (Threshold -> Anaerobic): P25 represents true interval threshold pace (~5:30 - 5:42 /km)
        $z4MinPace = !empty($b45Paces) ? (int) round($calcPercentile($b45Paces, 25)) : (int) round($z3MinPace * 0.86);
        if ($z4MinPace >= $z3MinPace - 20) {
            $z4MinPace = (int) round($z3MinPace * 0.86);
        }

        // Zone 1 ceiling / Zone 2 floor (Recovery pace): ~8:15 - 8:25 /km
        $z1MinPace = (int) round($z2MinPace * 1.08);

        return PaceZones::create(
            zoneOne: new PaceZone(PaceZone::ONE, PaceZone::DEFAULT_LABELS[PaceZone::ONE], $z1MinPace, null),
            zoneTwo: new PaceZone(PaceZone::TWO, PaceZone::DEFAULT_LABELS[PaceZone::TWO], $z2MinPace, $z1MinPace),
            zoneThree: new PaceZone(PaceZone::THREE, PaceZone::DEFAULT_LABELS[PaceZone::THREE], $z3MinPace, $z2MinPace),
            zoneFour: new PaceZone(PaceZone::FOUR, PaceZone::DEFAULT_LABELS[PaceZone::FOUR], $z4MinPace, $z3MinPace),
            zoneFive: new PaceZone(PaceZone::FIVE, PaceZone::DEFAULT_LABELS[PaceZone::FIVE], null, $z4MinPace),
        );
    }

    public function autoDetectThresholdPace(): int
    {
        $rows = $this->connection->executeQuery(
            <<<SQL
                SELECT be.distanceInMeter, be.timeInSeconds, a.startDateTime
                FROM ActivityBestEffort be
                INNER JOIN Activity a ON be.activityId = a.activityId
                WHERE be.sportType = 'Run'
                  AND be.distanceInMeter IN (3218, 5000, 10000)
                ORDER BY a.startDateTime DESC
            SQL
        )->fetchAllAssociative();

        if ([] === $rows) {
            return 0;
        }

        $now = $this->clock->getCurrentDateTimeImmutable()->getTimestamp();
        $pool90 = array_filter($rows, static fn (array $r): bool => ($now - strtotime($r['startDateTime'])) <= (90 * 86400));
        $pool180 = array_filter($rows, static fn (array $r): bool => ($now - strtotime($r['startDateTime'])) <= (180 * 86400));
        $pool = [] !== $pool90 ? $pool90 : ([] !== $pool180 ? $pool180 : $rows);

        $fastest5kSec = PHP_INT_MAX;
        $fastest10kSec = PHP_INT_MAX;
        $fastest3kSec = PHP_INT_MAX;

        foreach ($pool as $r) {
            $dist = (int) $r['distanceInMeter'];
            $sec = (int) $r['timeInSeconds'];
            if (5000 === $dist) {
                $fastest5kSec = min($fastest5kSec, $sec);
            } elseif (10000 === $dist) {
                $fastest10kSec = min($fastest10kSec, $sec);
            } elseif (3218 === $dist) {
                $fastest3kSec = min($fastest3kSec, $sec);
            }
        }

        if ($fastest5kSec < PHP_INT_MAX) {
            $pace5k = $fastest5kSec / 5.0; // sec per km
            // Jack Daniels VDOT / Joe Friel: Threshold pace is ~1.05 of 5k race pace
            return (int) round($pace5k * 1.05);
        }

        if ($fastest10kSec < PHP_INT_MAX) {
            $pace10k = $fastest10kSec / 10.0;
            return (int) round($pace10k * 0.98);
        }

        if ($fastest3kSec < PHP_INT_MAX) {
            $pace3k = $fastest3kSec / 3.218;
            return (int) round($pace3k * 1.10);
        }

        return 0;
    }

    /**
     * @return list<mixed>
     */
    private function getStreamData(ActivityId $activityId, StreamType $streamType): array
    {
        try {
            $stream = $this->activityStreamRepository->findOneByActivityAndStreamType($activityId, $streamType);

            return $stream->getData();
        } catch (EntityNotFound) {
            return [];
        }
    }
}
