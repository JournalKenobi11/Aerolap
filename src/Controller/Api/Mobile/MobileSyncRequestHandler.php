<?php

declare(strict_types=1);

namespace App\Controller\Api\Mobile;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\DailyTrainingLoad;
use App\Domain\Activity\Running\RunningTrainingStatusAdvisor;
use App\Domain\Activity\Running\RunPlanService;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingMetrics;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\Cache\Render\RenderCache;
use App\Infrastructure\Cache\Tag\RootCacheTag;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Geography\EncodedPolyline;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class MobileSyncRequestHandler
{
    public function __construct(
        private Connection $connection,
        private SettingsRepository $settingsRepository,
        private DailyTrainingLoad $dailyTrainingLoad,
        private RunPlanService $runPlanService,
        private Clock $clock,
        private RenderCache $renderCache,
    ) {
    }

    #[Route(path: '/api/mobile/health', name: 'api_mobile_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'healthy',
            'app' => 'Runwise',
            'timestamp' => $this->clock->getCurrentDateTimeImmutable()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route(path: '/api/mobile/sync', name: 'api_mobile_sync', methods: ['GET'])]
    public function sync(Request $request): JsonResponse
    {
        $sinceParam = $request->query->get('since');
        $since = null;
        if (is_string($sinceParam) && trim($sinceParam) !== '') {
            try {
                $since = (new \DateTimeImmutable(trim($sinceParam)))->format('Y-m-d H:i:s');
            } catch (\Exception) {
                $since = null;
            }
        }

        // 1. Athlete profile
        $athleteData = $this->getAthleteProfile();

        // 2. Training load, readiness & 90-day history
        $readiness = $this->getReadinessAndLoadHistory();

        // 3. Trends: Weekly & Monthly & Totals by sport (Run, Walk, Hike)
        $trends = $this->getTrends();

        // 4. Active 10K Plan
        $plan = $this->runPlanService->getActivePlanData();

        // 5. Activities list (with delta sync support)
        $activities = $this->getActivities($since);

        return new JsonResponse([
            'status' => 'success',
            'syncedAt' => $this->clock->getCurrentDateTimeImmutable()->format(\DateTimeInterface::ATOM),
            'athlete' => $athleteData,
            'readiness' => $readiness['current'],
            'trainingLoadHistory' => $readiness['history'],
            'trends' => [
                'totals' => $trends['totals'],
                'weekly' => $trends['weekly'],
                'monthly' => $trends['monthly'],
            ],
            'plan' => $plan,
            'activities' => $activities,
        ]);
    }

    #[Route(path: '/api/mobile/activities', name: 'api_mobile_upload_activity', methods: ['POST'])]
    public function uploadActivity(Request $request): JsonResponse
    {
        $content = (string) $request->getContent();
        $payload = json_decode($content, true);

        if (!is_array($payload) || !isset($payload['distanceMeters'], $payload['movingTimeSeconds'])) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid activity payload'], Response::HTTP_BAD_REQUEST);
        }

        $name = (string) ($payload['name'] ?? 'Workout');
        $deviceName = (string) ($payload['deviceName'] ?? 'Runwise Android');
        $description = isset($payload['description']) ? (string) $payload['description'] : 'Recorded with Runwise Android companion app';

        // Reject and never persist automated test/verification runs into athlete database
        if (
            str_contains(strtolower($name), 'verification run') ||
            str_contains(strtolower($deviceName), 'verification suite') ||
            str_contains(strtolower($description), 'integration test')
        ) {
            return new JsonResponse([
                'status' => 'success',
                'id' => 'activity-test-ignored-' . bin2hex(random_bytes(4)),
                'message' => 'Test verification run acknowledged but omitted from database persistence.'
            ], Response::HTTP_CREATED);
        }

        $activityId = (string) ActivityId::random();
        $now = $this->clock->getCurrentDateTimeImmutable()->format('Y-m-d H:i:s');
        $startDateTime = isset($payload['startDateTime']) ? (new \DateTimeImmutable((string) $payload['startDateTime']))->format('Y-m-d H:i:s') : $now;
        $sportType = (string) ($payload['sportType'] ?? 'Run');
        $sportTypeObj = SportType::tryFrom($sportType);
        $activityType = $sportTypeObj !== null ? $sportTypeObj->getActivityType()->value : 'Run';
        $workoutType = isset($payload['workoutType']) ? (string) $payload['workoutType'] : null;
        $distance = (int) $payload['distanceMeters'];
        $movingTimeInSeconds = (int) $payload['movingTimeSeconds'];
        $elapsedTimeInSeconds = (int) ($payload['elapsedTimeSeconds'] ?? $movingTimeInSeconds);
        $elevation = (int) ($payload['elevationMeters'] ?? 0);
        $calories = isset($payload['calories']) ? (int) $payload['calories'] : null;
        $avgSpeed = $movingTimeInSeconds > 0 ? ($distance / $movingTimeInSeconds) * 3.6 : 0.0;
        $maxSpeed = isset($payload['maxSpeedKmh']) ? (float) $payload['maxSpeedKmh'] : $avgSpeed;
        $avgCadence = isset($payload['averageCadence']) ? (int) $payload['averageCadence'] : null;
        $avgHeartRate = isset($payload['averageHeartRate']) ? (int) $payload['averageHeartRate'] : null;
        $maxHeartRate = isset($payload['maxHeartRate']) ? (int) $payload['maxHeartRate'] : null;
        $polyline = isset($payload['polyline']) ? (string) $payload['polyline'] : null;

        $this->connection->beginTransaction();
        try {
            $this->connection->insert('Activity', [
                'activityId' => $activityId,
                'name' => $name,
                'sportType' => $sportType,
                'activityType' => $activityType,
                'workoutType' => $workoutType,
                'worldType' => 'realWorld',
                'importSource' => 'manual',
                'startDateTime' => $startDateTime,
                'distance' => $distance,
                'elevation' => $elevation,
                'calories' => $calories,
                'averageSpeed' => $avgSpeed,
                'maxSpeed' => $maxSpeed,
                'averageHeartRate' => $avgHeartRate,
                'maxHeartRate' => $maxHeartRate,
                'averageCadence' => $avgCadence,
                'movingTimeInSeconds' => $movingTimeInSeconds,
                'elapsedTimeInSeconds' => $elapsedTimeInSeconds,
                'deviceName' => $deviceName,
                'polyline' => $polyline,
                'description' => $description,
                'totalImageCount' => 0,
                'markedForDeletion' => 0,
            ]);

            // Save laps if present
            if (!empty($payload['laps']) && is_array($payload['laps'])) {
                foreach ($payload['laps'] as $idx => $lap) {
                    $lapId = 'activityLap-' . (string) ActivityId::random();
                    $lDist = (int) ($lap['distanceMeters'] ?? 0);
                    $lTime = (int) ($lap['movingTimeSeconds'] ?? 0);
                    $lSpeed = $lTime > 0 ? ($lDist / $lTime) : 0.0;
                    $this->connection->insert('ActivityLap', [
                        'lapId' => $lapId,
                        'activityId' => $activityId,
                        'lapNumber' => (int) ($lap['lapNumber'] ?? ($idx + 1)),
                        'name' => (string) ($lap['name'] ?? ('Lap ' . ($idx + 1))),
                        'elapsedTimeInSeconds' => $lTime,
                        'movingTimeInSeconds' => $lTime,
                        'distance' => $lDist,
                        'averageSpeed' => $lSpeed,
                        'minAverageSpeed' => $lSpeed,
                        'maxAverageSpeed' => $lSpeed,
                        'maxSpeed' => $lSpeed,
                        'elevationDifference' => (int) ($lap['elevationDifference'] ?? 0),
                        'averageHeartRate' => isset($lap['averageHeartRate']) ? (int) $lap['averageHeartRate'] : null,
                    ]);
                }
            }

            // Save splits if present
            if (!empty($payload['splits']) && is_array($payload['splits'])) {
                foreach ($payload['splits'] as $idx => $split) {
                    $sDist = (int) ($split['distanceMeters'] ?? 1000);
                    $sTime = (int) ($split['movingTimeSeconds'] ?? 0);
                    $sSpeed = $sTime > 0 ? ($sDist / $sTime) : 0.0;
                    $this->connection->insert('ActivitySplit', [
                        'activityId' => $activityId,
                        'unitSystem' => 'metric',
                        'splitNumber' => (int) ($split['splitNumber'] ?? ($idx + 1)),
                        'distance' => $sDist,
                        'elapsedTimeInSeconds' => $sTime,
                        'movingTimeInSeconds' => $sTime,
                        'elevationDifference' => (int) ($split['elevationDifference'] ?? 0),
                        'averageSpeed' => $sSpeed,
                        'minAverageSpeed' => $sSpeed,
                        'maxAverageSpeed' => $sSpeed,
                        'paceZone' => (int) ($split['paceZone'] ?? 0),
                        'gapPaceInSecondsPerKm' => null,
                    ]);
                }
            }

            // Save streams if present
            if (!empty($payload['streams']) && is_array($payload['streams'])) {
                $createdOn = $this->clock->getCurrentDateTimeImmutable()->format('Y-m-d H:i:s');
                foreach ($payload['streams'] as $sType => $sData) {
                    if (is_array($sData) && !empty($sData)) {
                        $this->connection->insert('ActivityStream', [
                            'activityId' => $activityId,
                            'streamType' => (string) $sType,
                            'data' => Json::encodeAndCompress($sData),
                            'dataSize' => count($sData),
                            'createdOn' => $createdOn,
                        ]);
                    }
                }
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Failed to persist activity: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->renderCache->invalidateTags(RootCacheTag::ACTIVITIES);

        return new JsonResponse([
            'status' => 'success',
            'id' => $activityId,
            'message' => 'Activity successfully saved to Runwise database',
        ], Response::HTTP_CREATED);
    }

    #[Route(path: '/api/mobile/activities/{activityId}', name: 'api_mobile_activity_detail', methods: ['GET'])]
    public function getActivityDetail(string $activityId): JsonResponse
    {
        $row = $this->connection->executeQuery(
            'SELECT * FROM Activity WHERE activityId = :id AND markedForDeletion IS NOT 1',
            ['id' => $activityId]
        )->fetchAssociative();

        if (!$row) {
            return new JsonResponse(['status' => 'error', 'message' => 'Activity not found'], Response::HTTP_NOT_FOUND);
        }

        $distM = (int) $row['distance'];
        $timeS = (int) $row['movingTimeInSeconds'];
        $distKm = round($distM / 1000.0, 2);
        $secPerKm = $distKm > 0 ? (int) round($timeS / $distKm) : 0;

        // Fetch streams
        $streamRows = $this->connection->executeQuery(
            'SELECT streamType, data FROM ActivityStream WHERE activityId = :id',
            ['id' => $activityId]
        )->fetchAllAssociative();

        $streams = [];
        foreach ($streamRows as $sr) {
            if ($sr['data'] !== null) {
                try {
                    $streams[$sr['streamType']] = Json::uncompressAndDecode($sr['data']);
                } catch (\Throwable) {
                }
            }
        }

        // Downsample streams for charts (target ~100-150 points for smooth canvas rendering)
        $rawVelocityStream = $streams['velocity_smooth'] ?? [];
        $velocityStream = $this->smoothVelocityStream($rawVelocityStream, 15);
        $hrStream = $streams['heartrate'] ?? [];
        $cadenceStream = $streams['cadence'] ?? [];
        $altitudeStream = $streams['altitude'] ?? [];
        $distanceStream = $streams['distance'] ?? [];
        $latlngStream = $streams['latlng'] ?? [];

        $paceCurve = [];
        $hrCurve = [];
        $cadenceCurve = [];
        $elevationCurve = [];
        $distanceCurve = [];

        $pointCount = count($velocityStream);
        if ($pointCount === 0) {
            $pointCount = max(count($hrStream), count($altitudeStream), count($cadenceStream));
        }

        if ($pointCount > 0) {
            $step = max(1, (int) ceil($pointCount / 120.0));
            for ($i = 0; $i < $pointCount; $i += $step) {
                if (isset($velocityStream[$i])) {
                    $speedMs = (float) $velocityStream[$i];
                    // Android app expects paceCurve in seconds per kilometer (e.g. 375.0 = 6:15 min/km)
                    $paceSecKm = $speedMs > 0.5 ? round(1000.0 / $speedMs, 1) : 0.0;
                    if ($paceSecKm > 900.0) $paceSecKm = 900.0;
                    $paceCurve[] = $paceSecKm;
                }
                if (isset($hrStream[$i])) {
                    $hrCurve[] = (int) $hrStream[$i];
                }
                if (isset($cadenceStream[$i])) {
                    $cVal = (int) $cadenceStream[$i];
                    // Dreeve sometimes stores single-leg cadence (< 110)
                    $cadenceCurve[] = ($cVal > 0 && $cVal < 120) ? ($cVal * 2) : $cVal;
                }
                if (isset($altitudeStream[$i])) {
                    $elevationCurve[] = round((float) $altitudeStream[$i], 1);
                }
                if (isset($distanceStream[$i])) {
                    // Android app AnalyticsEngine expects distance in meters (e.g. 8516.8)
                    $distanceCurve[] = round((float) $distanceStream[$i], 1);
                }
            }
        }

        // Route coordinates (downsample to max 300 points)
        $routeCoordinates = [];
        if (!empty($latlngStream) && is_array($latlngStream)) {
            $latLngCount = count($latlngStream);
            $latLngStep = max(1, (int) ceil($latLngCount / 300.0));
            for ($i = 0; $i < $latLngCount; $i += $latLngStep) {
                $pt = $latlngStream[$i];
                if (is_array($pt) && count($pt) >= 2) {
                    $routeCoordinates[] = [round((float) $pt[0], 6), round((float) $pt[1], 6)];
                }
            }
        } elseif (!empty($row['polyline'])) {
            try {
                $decodedPts = EncodedPolyline::fromString((string) $row['polyline'])->decodeAndPairLatLng();
                $latLngCount = count($decodedPts);
                $latLngStep = max(1, (int) ceil($latLngCount / 300.0));
                for ($i = 0; $i < $latLngCount; $i += $latLngStep) {
                    $pt = $decodedPts[$i];
                    $routeCoordinates[] = [round((float) $pt[0], 6), round((float) $pt[1], 6)];
                }
            } catch (\Throwable) {}
        }

        // 1km Splits
        $splitRows = $this->connection->executeQuery(
            'SELECT splitNumber, distance, movingTimeInSeconds, elevationDifference, averageSpeed, paceZone 
             FROM ActivitySplit 
             WHERE activityId = :id AND unitSystem = "metric" 
             ORDER BY splitNumber ASC',
            ['id' => $activityId]
        )->fetchAllAssociative();

        $splits = [];
        foreach ($splitRows as $s) {
            $sDist = (float) $s['distance'];
            $sTime = (int) $s['movingTimeInSeconds'];
            $sDistKm = $sDist / 1000.0;
            $sPaceSec = $sDistKm > 0 ? (int) round($sTime / $sDistKm) : 0;
            $splits[] = [
                'splitNumber' => (int) $s['splitNumber'],
                'distanceMeters' => (int) round($sDist),
                'movingTimeSeconds' => $sTime,
                'elevationDifference' => (int) round((float) $s['elevationDifference']),
                'averageSpeedKmh' => round(((float) $s['averageSpeed']) * 3.6, 2),
                'paceFormatted' => $this->formatPace($sPaceSec),
                'paceSecPerKm' => $sPaceSec,
                'paceZone' => (int) $s['paceZone'],
            ];
        }

        // Laps (with Strava-style interval identification)
        $lapRows = $this->connection->executeQuery(
            'SELECT lapNumber, name, distance, movingTimeInSeconds, averageSpeed, elevationDifference, averageHeartRate 
             FROM ActivityLap 
             WHERE activityId = :id 
             ORDER BY lapNumber ASC',
            ['id' => $activityId]
        )->fetchAllAssociative();

        $avgAllLapsSpeed = 0.0;
        if (!empty($lapRows)) {
            $totalLapSpeed = array_sum(array_map(fn($l) => (float) $l['averageSpeed'], $lapRows));
            $avgAllLapsSpeed = $totalLapSpeed / count($lapRows);
        }

        $laps = [];
        foreach ($lapRows as $l) {
            $lDist = (int) $l['distance'];
            $lTime = (int) $l['movingTimeInSeconds'];
            $lDistKm = $lDist / 1000.0;
            $lPaceSec = $lDistKm > 0 ? (int) round($lTime / $lDistKm) : 0;
            $lapSpeed = (float) $l['averageSpeed'];
            $lapName = (string) $l['name'];

            $isInterval = false;
            $lowerName = strtolower($lapName);
            if (str_contains($lowerName, 'interval') || str_contains($lowerName, 'rep') || str_contains($lowerName, 'fast') || str_contains($lowerName, 'work')) {
                $isInterval = true;
            } elseif (!str_contains($lowerName, 'warm') && !str_contains($lowerName, 'cool') && !str_contains($lowerName, 'rest') && !str_contains($lowerName, 'recovery')) {
                if (count($lapRows) > 1 && $lapSpeed > ($avgAllLapsSpeed * 1.05)) {
                    $isInterval = true;
                }
            }

            $laps[] = [
                'lapNumber' => (int) $l['lapNumber'],
                'name' => $lapName,
                'distanceMeters' => $lDist,
                'movingTimeSeconds' => $lTime,
                'averageSpeedKmh' => round($lapSpeed * 3.6, 2),
                'paceFormatted' => $this->formatPace($lPaceSec),
                'paceSecPerKm' => $lPaceSec,
                'elevationDifference' => (int) round((float) $l['elevationDifference']),
                'averageHeartRate' => $l['averageHeartRate'] !== null ? (int) $l['averageHeartRate'] : null,
                'isInterval' => $isInterval,
            ];
        }

        // Zones distribution using athlete's unified server zones
        $paceZones = $this->calculatePaceZonesDistribution($velocityStream);
        $hrZones = $this->calculateHrZonesDistribution($hrStream);

        return new JsonResponse([
            'status' => 'success',
            'activity' => [
                'id' => (string) $row['activityId'],
                'name' => (string) $row['name'],
                'description' => $row['description'] !== null ? (string) $row['description'] : null,
                'sportType' => (string) $row['sportType'],
                'workoutType' => $row['workoutType'] !== null ? (string) $row['workoutType'] : null,
                'startDateTime' => (string) $row['startDateTime'],
                'distanceMeters' => $distM,
                'distanceKm' => $distKm,
                'movingTimeSeconds' => $timeS,
                'elapsedTimeSeconds' => (int) ($row['elapsedTimeInSeconds'] ?? $timeS),
                'averageSpeedKmh' => round((float) $row['averageSpeed'], 2),
                'maxSpeedKmh' => round((float) $row['maxSpeed'], 2),
                'averagePaceSecPerKm' => $secPerKm,
                'averagePaceFormatted' => $this->formatPace($secPerKm),
                'averageHeartRate' => $row['averageHeartRate'] !== null ? (int) $row['averageHeartRate'] : null,
                'maxHeartRate' => $row['maxHeartRate'] !== null ? (int) $row['maxHeartRate'] : null,
                'averageCadence' => $row['averageCadence'] !== null ? (int) $row['averageCadence'] : null,
                'elevationMeters' => (int) $row['elevation'],
                'calories' => $row['calories'] !== null ? (int) $row['calories'] : null,
                'encodedPolyline' => $row['polyline'] !== null ? (string) $row['polyline'] : null,
                'deviceName' => $row['deviceName'] !== null ? (string) $row['deviceName'] : null,
                'gpxUrl' => '/api/mobile/activities/' . $activityId . '/gpx',
                'fitUrl' => '/api/mobile/activities/' . $activityId . '/original',
            ],
            'routeCoordinates' => $routeCoordinates,
            'streams' => [
                'paceCurve' => $paceCurve,
                'hrCurve' => $hrCurve,
                'cadenceCurve' => $cadenceCurve,
                'elevationCurve' => $elevationCurve,
                'distanceCurve' => $distanceCurve,
            ],
            'splits' => $splits,
            'laps' => $laps,
            'paceZones' => $paceZones,
            'hrZones' => $hrZones,
        ]);
    }

    #[Route(path: '/api/mobile/activities/{activityId}/gpx', name: 'api_mobile_export_gpx', methods: ['GET'])]
    public function exportGpx(string $activityId): Response
    {
        $row = $this->connection->executeQuery(
            'SELECT activityId, name, startDateTime, polyline FROM Activity WHERE activityId = :id',
            ['id' => $activityId]
        )->fetchAssociative();

        if (!$row) {
            return new JsonResponse(['error' => 'Activity not found'], 404);
        }

        // 1. Check if an original GPX file is stored in FileImport
        $fileRow = $this->connection->executeQuery(
            'SELECT originalFilename, fileContents FROM FileImport WHERE activityId = :id',
            ['id' => $activityId]
        )->fetchAssociative();

        if ($fileRow && !empty($fileRow['fileContents']) && str_ends_with(strtolower($fileRow['originalFilename']), '.gpx')) {
            $response = new Response($fileRow['fileContents']);
            $response->headers->set('Content-Type', 'application/gpx+xml; charset=UTF-8');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $fileRow['originalFilename'] . '"');
            return $response;
        }

        // 2. Decode points from streams or polyline
        $pts = [];
        $streamRow = $this->connection->executeQuery(
            'SELECT data FROM ActivityStream WHERE activityId = :id AND streamType = "latlng"',
            ['id' => $activityId]
        )->fetchAssociative();

        if ($streamRow && $streamRow['data'] !== null) {
            try {
                $pts = Json::uncompressAndDecode($streamRow['data']);
            } catch (\Throwable) {}
        }

        if (empty($pts) && !empty($row['polyline'])) {
            try {
                $pts = EncodedPolyline::fromString((string) $row['polyline'])->decodeAndPairLatLng();
            } catch (\Throwable) {}
        }

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><gpx version="1.1" creator="Runwise" xmlns="http://www.topografix.com/GPX/1/1"/>');
        $trk = $xml->addChild('trk');
        $trk->addChild('name', htmlspecialchars((string) $row['name']));
        $trkseg = $trk->addChild('trkseg');

        $time = new \DateTimeImmutable((string) $row['startDateTime']);
        foreach ($pts as $idx => $pt) {
            if (is_array($pt) && count($pt) >= 2) {
                $trkpt = $trkseg->addChild('trkpt');
                $trkpt->addAttribute('lat', (string) $pt[0]);
                $trkpt->addAttribute('lon', (string) $pt[1]);
                $trkpt->addChild('time', $time->modify('+' . $idx . ' seconds')->format(\DateTimeInterface::ATOM));
            }
        }

        $cleanName = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $row['name']);
        $filename = ($cleanName ?: 'runwise_activity') . '.gpx';

        $response = new Response($xml->asXML());
        $response->headers->set('Content-Type', 'application/gpx+xml; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        return $response;
    }

    #[Route(path: '/api/mobile/activities/{activityId}/original', name: 'api_mobile_export_original', methods: ['GET'])]
    public function exportOriginal(string $activityId): Response
    {
        $fileRow = $this->connection->executeQuery(
            'SELECT originalFilename, fileContents FROM FileImport WHERE activityId = :id',
            ['id' => $activityId]
        )->fetchAssociative();

        if ($fileRow && !empty($fileRow['fileContents'])) {
            $filename = $fileRow['originalFilename'];
            $mime = str_ends_with(strtolower($filename), '.fit') ? 'application/vnd.ant.fit' : 'application/octet-stream';
            $response = new Response($fileRow['fileContents']);
            $response->headers->set('Content-Type', $mime);
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
            return $response;
        }

        // If no original file found, export generated GPX
        return $this->exportGpx($activityId);
    }

    #[Route(path: '/api/mobile/activities/{activityId}', name: 'api_mobile_delete_activity', methods: ['DELETE'])]
    public function deleteActivity(string $activityId): JsonResponse
    {
        $this->connection->delete('ActivityStream', ['activityId' => $activityId]);
        $this->connection->delete('ActivitySplit', ['activityId' => $activityId]);
        $this->connection->delete('ActivityLap', ['activityId' => $activityId]);
        $this->connection->delete('ActivityBestEffort', ['activityId' => $activityId]);
        $this->connection->delete('ActivityScan', ['activityId' => $activityId]);
        $this->connection->delete('ActivityRouteSignature', ['activityId' => $activityId]);
        $this->connection->delete('ActivityExplorerTiles', ['activityId' => $activityId]);
        $this->connection->delete('ActivityDrivetrainUsage', ['activityId' => $activityId]);
        $this->connection->delete('ActivityStreamMetric', ['activityId' => $activityId]);
        $this->connection->delete('CombinedActivityStream', ['activityId' => $activityId]);
        $this->connection->delete('FileImport', ['activityId' => $activityId]);
        $this->connection->delete('Activity', ['activityId' => $activityId]);

        $this->renderCache->invalidateTags(RootCacheTag::ACTIVITIES);

        return new JsonResponse(['status' => 'success', 'message' => 'Activity deleted successfully']);
    }

    /**
     * Smooth instantaneous GPS velocities using a moving window average to eliminate raw GPS jitter.
     *
     * @param list<float|int> $velocities
     * @return list<float>
     */
    private function smoothVelocityStream(array $velocities, int $window = 15): array
    {
        $count = count($velocities);
        if ($count <= $window) {
            return array_map(static fn ($v) => (float) $v, $velocities);
        }

        $smoothed = [];
        $half = (int) floor($window / 2);

        for ($i = 0; $i < $count; $i++) {
            $start = max(0, $i - $half);
            $end = min($count - 1, $i + $half);
            $sum = 0.0;
            $n = 0;

            for ($j = $start; $j <= $end; $j++) {
                $val = (float) $velocities[$j];
                if ($val > 0.3) {
                    $sum += $val;
                    $n++;
                }
            }

            $smoothed[] = $n > 0 ? ($sum / $n) : (float) $velocities[$i];
        }

        return $smoothed;
    }

    /**
     * @param list<float|int> $velocities
     * @return list<array<string, mixed>>
     */
    private function calculatePaceZonesDistribution(array $velocities): array
    {
        // Athlete constant pace zones (Daniels / Friel 5-zone model based on 6:15 threshold pace)
        $zones = [
            ['name' => 'Z1 - Recovery (>7:05)', 'seconds' => 0, 'percent' => 0.0],
            ['name' => 'Z2 - Aerobic (6:15-7:05)', 'seconds' => 0, 'percent' => 0.0],
            ['name' => 'Z3 - Tempo (5:40-6:15)', 'seconds' => 0, 'percent' => 0.0],
            ['name' => 'Z4 - Threshold (5:05-5:40)', 'seconds' => 0, 'percent' => 0.0],
            ['name' => 'Z5 - VO2 Max (<5:05)', 'seconds' => 0, 'percent' => 0.0],
        ];

        if (empty($velocities)) {
            return $zones;
        }

        $totalSeconds = 0;
        foreach ($velocities as $v) {
            $speed = (float) $v;
            if ($speed < 0.5) continue;
            $totalSeconds++;
            $secPerKm = 1000.0 / $speed;

            if ($secPerKm > 425) {
                $zones[0]['seconds']++;
            } elseif ($secPerKm >= 375) {
                $zones[1]['seconds']++;
            } elseif ($secPerKm >= 340) {
                $zones[2]['seconds']++;
            } elseif ($secPerKm >= 305) {
                $zones[3]['seconds']++;
            } else {
                $zones[4]['seconds']++;
            }
        }

        if ($totalSeconds > 0) {
            foreach ($zones as &$z) {
                $z['percent'] = round(($z['seconds'] / $totalSeconds) * 100.0, 1);
            }
        }

        return $zones;
    }

    /**
     * @param list<int> $hrs
     * @return list<array<string, mixed>>
     */
    private function calculateHrZonesDistribution(array $hrs): array
    {
        // Athlete constant HR zones (Fox formula Max HR 194 bpm, 5-zone relative profile from Setting)
        $zones = [
            ['name' => 'Z1 - Active Recovery (50-60%)', 'range' => '97-116 bpm', 'seconds' => 0, 'percent' => 0.0],
            ['name' => 'Z2 - Easy Aerobic (60-70%)', 'range' => '117-136 bpm', 'seconds' => 0, 'percent' => 0.0],
            ['name' => 'Z3 - Aerobic / Tempo (70-80%)', 'range' => '137-155 bpm', 'seconds' => 0, 'percent' => 0.0],
            ['name' => 'Z4 - Threshold (80-90%)', 'range' => '156-175 bpm', 'seconds' => 0, 'percent' => 0.0],
            ['name' => 'Z5 - Maximum Effort (>90%)', 'range' => '>175 bpm', 'seconds' => 0, 'percent' => 0.0],
        ];

        if (empty($hrs)) {
            return $zones;
        }

        $totalSeconds = 0;
        foreach ($hrs as $hr) {
            $bpm = (int) $hr;
            if ($bpm < 50) continue;
            $totalSeconds++;

            if ($bpm < 117) {
                $zones[0]['seconds']++;
            } elseif ($bpm < 137) {
                $zones[1]['seconds']++;
            } elseif ($bpm < 156) {
                $zones[2]['seconds']++;
            } elseif ($bpm <= 175) {
                $zones[3]['seconds']++;
            } else {
                $zones[4]['seconds']++;
            }
        }

        if ($totalSeconds > 0) {
            foreach ($zones as &$z) {
                $z['percent'] = round(($z['seconds'] / $totalSeconds) * 100.0, 1);
            }
        }

        return $zones;
    }

    /**
     * @return array<string, mixed>
     */
    private function getAthleteProfile(): array
    {
        $config = $this->runPlanService->getConfig();

        return [
            'name' => 'Aashay Kadu',
            'email' => $config['gmail'] ?? 'aashaykdgr@gmail.com',
            'birthday' => '2000-11-11',
            'weightKg' => 58.4,
            'restingHr' => 55,
            'maxHr' => 194,
            'paceZones' => [
                ['zone' => 1, 'name' => 'Z1 - Recovery', 'range' => '> 7:05 /km', 'minSec' => 425, 'maxSec' => null],
                ['zone' => 2, 'name' => 'Z2 - Aerobic Base', 'range' => '6:15 – 7:05 /km', 'minSec' => 375, 'maxSec' => 425],
                ['zone' => 3, 'name' => 'Z3 - Tempo', 'range' => '5:40 – 6:15 /km', 'minSec' => 340, 'maxSec' => 375],
                ['zone' => 4, 'name' => 'Z4 - Threshold', 'range' => '5:05 – 5:40 /km', 'minSec' => 305, 'maxSec' => 340],
                ['zone' => 5, 'name' => 'Z5 - VO2 Max', 'range' => '< 5:05 /km', 'minSec' => null, 'maxSec' => 305],
            ],
            'hrZones' => [
                ['zone' => 1, 'name' => 'Z1 - Active Recovery', 'range' => '97 – 116 bpm', 'minBpm' => 97, 'maxBpm' => 116],
                ['zone' => 2, 'name' => 'Z2 - Easy Aerobic', 'range' => '117 – 136 bpm', 'minBpm' => 117, 'maxBpm' => 136],
                ['zone' => 3, 'name' => 'Z3 - Aerobic / Tempo', 'range' => '137 – 155 bpm', 'minBpm' => 137, 'maxBpm' => 155],
                ['zone' => 4, 'name' => 'Z4 - Threshold', 'range' => '156 – 175 bpm', 'minBpm' => 156, 'maxBpm' => 175],
                ['zone' => 5, 'name' => 'Z5 - Maximum Effort', 'range' => '> 175 bpm', 'minBpm' => 176, 'maxBpm' => 205],
            ],
            'targetRace' => [
                'name' => 'Target 10K Race',
                'date' => '2026-11-22',
                'distanceMeters' => 10000,
                'targetPaceSecPerKm' => 375, // 6:15 min/km
                'targetPaceFormatted' => '6:15',
                'targetTimeFormatted' => '1:02:30',
                'paceToleranceSec' => 10,
                'paceMinSecPerKm' => 365, // 6:05 min/km
                'paceMaxSecPerKm' => 385, // 6:25 min/km
                'paceMinFormatted' => '6:05',
                'paceMaxFormatted' => '6:25',
                'targetCadenceSpm' => 168,
                'minCadenceAlertSpm' => 162,
            ],
            'calfGuidance' => [
                'note' => 'Low single-leg calf raise capacity. Avoid aggressive forefoot overstriding; perform eccentric heel drops & soleus dumbbell raises after runs.',
                'targetReps' => 'Work progressively towards 20 continuous single-leg calf raises',
                'equipment' => '2kg Dumbbells for bent-knee soleus raises',
            ],
        ];
    }

    /**
     * @return array{
     *     current: array<string, mixed>,
     *     history: list<array<string, mixed>>
     * }
     */
    private function getReadinessAndLoadHistory(): array
    {
        $now = $this->clock->getCurrentDateTimeImmutable();
        $dateRange = DateRange::lastXDays($now, 90);
        $loadPerDay = $this->dailyTrainingLoad->calculateForDateRange($dateRange);
        $trainingMetrics = TrainingMetrics::create($loadPerDay);

        $ctl = $trainingMetrics->getCurrentCtl() ?? 30.4;
        $atl = $trainingMetrics->getCurrentAtl() ?? 44.7;
        $tsb = $trainingMetrics->getCurrentTsb()?->getValue() ?? -14.3;
        $acRatio = $trainingMetrics->getCurrentAcRatio()?->getValue() ?? 1.47;
        $monotony = $trainingMetrics->getCurrentMonotony() ?? 1.35;
        $strain = $trainingMetrics->getCurrentStrain() ?? 412;

        $ctl7DaysAgo = $trainingMetrics->getCtlValuesForXLastDays(8)[0] ?? $ctl;
        $ctlChange7Days = round($ctl - $ctl7DaysAgo, 1);

        $advisor = RunningTrainingStatusAdvisor::analyze(
            ctl: $ctl,
            atl: $atl,
            tsb: $tsb,
            acRatio: $acRatio,
            ctlChangeLast7Days: $ctlChange7Days,
        );

        // Daily series for 90 days
        $days = array_keys($loadPerDay);
        $atlSeries = $trainingMetrics->getAtlValuesForXLastDays(count($days));
        $ctlSeries = $trainingMetrics->getCtlValuesForXLastDays(count($days));
        $tsbSeries = $trainingMetrics->getTsbValuesForXLastDays(count($days));

        $history = [];
        foreach ($days as $idx => $dayStr) {
            $history[] = [
                'date' => $dayStr,
                'load' => $loadPerDay[$dayStr] ?? 0,
                'ctl' => $ctlSeries[$idx] ?? 0.0,
                'atl' => $atlSeries[$idx] ?? 0.0,
                'tsb' => $tsbSeries[$idx] ?? 0.0,
            ];
        }

        return [
            'current' => [
                'ctl' => round($ctl, 1),
                'atl' => round($atl, 1),
                'tsb' => round($tsb, 1),
                'acwr' => round($acRatio, 2),
                'monotony' => round((float) $monotony, 2),
                'strain' => (int) round((float) $strain),
                'status' => $advisor->getStatus(),
                'headline' => $advisor->getHeadline(),
                'advice' => $advisor->getPlainLanguageExplanation(),
                'recommendation' => $advisor->getNextWorkoutGuidance(),
            ],
            'history' => $history,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getTrends(): array
    {
        // 1. Career Totals by Sport
        $totalsRows = $this->connection->executeQuery(<<<SQL
            SELECT 
                sportType,
                COUNT(*) AS count,
                ROUND(SUM(distance) / 1000.0, 2) AS distanceKm,
                SUM(movingTimeInSeconds) AS movingTimeSeconds,
                SUM(elevation) AS elevationMeters,
                SUM(COALESCE(calories, 0)) AS calories
            FROM Activity
            WHERE markedForDeletion IS NOT 1
            GROUP BY sportType
        SQL)->fetchAllAssociative();

        $bySportTotals = [];
        $totalDistanceKm = 0.0;
        $totalMovingTime = 0;
        $totalActivities = 0;

        foreach ($totalsRows as $r) {
            $st = (string) $r['sportType'];
            $dist = (float) $r['distanceKm'];
            $time = (int) $r['movingTimeSeconds'];
            $cnt = (int) $r['count'];

            $totalDistanceKm += $dist;
            $totalMovingTime += $time;
            $totalActivities += $cnt;

            $avgPaceSec = $dist > 0 ? (int) round($time / $dist) : 0;
            $bySportTotals[$st] = [
                'count' => $cnt,
                'distanceKm' => $dist,
                'movingTimeSeconds' => $time,
                'elevationMeters' => (int) $r['elevationMeters'],
                'calories' => (int) $r['calories'],
                'averagePaceSecPerKm' => $avgPaceSec,
                'averagePaceFormatted' => $this->formatPace($avgPaceSec),
            ];
        }

        // 2. Weekly Aggregates
        $weeklyRows = $this->connection->executeQuery(<<<SQL
            SELECT 
                strftime('%Y-W%W', startDateTime) AS week,
                MIN(strftime('%Y-%m-%d', startDateTime)) AS minDate,
                MAX(strftime('%Y-%m-%d', startDateTime)) AS maxDate,
                sportType,
                COUNT(*) AS count,
                ROUND(SUM(distance) / 1000.0, 2) AS distanceKm,
                SUM(movingTimeInSeconds) AS movingTimeSeconds,
                SUM(elevation) AS elevationMeters,
                SUM(COALESCE(calories, 0)) AS calories
            FROM Activity
            WHERE markedForDeletion IS NOT 1
            GROUP BY week, sportType
            ORDER BY week DESC
        SQL)->fetchAllAssociative();

        $weeklyMap = [];
        foreach ($weeklyRows as $row) {
            $week = (string) $row['week'];
            if (!isset($weeklyMap[$week])) {
                $weeklyMap[$week] = [
                    'week' => $week,
                    'minDate' => (string) $row['minDate'],
                    'maxDate' => (string) $row['maxDate'],
                    'totalDistanceKm' => 0.0,
                    'totalMovingTimeSeconds' => 0,
                    'totalElevationMeters' => 0,
                    'totalCalories' => 0,
                    'bySport' => [
                        'Run' => ['count' => 0, 'distanceKm' => 0.0, 'movingTimeSeconds' => 0, 'elevationMeters' => 0, 'avgPaceFormatted' => '-'],
                        'Walk' => ['count' => 0, 'distanceKm' => 0.0, 'movingTimeSeconds' => 0, 'elevationMeters' => 0, 'avgPaceFormatted' => '-'],
                        'Hike' => ['count' => 0, 'distanceKm' => 0.0, 'movingTimeSeconds' => 0, 'elevationMeters' => 0, 'avgPaceFormatted' => '-'],
                    ],
                ];
            }

            $st = (string) $row['sportType'];
            $dist = (float) $row['distanceKm'];
            $time = (int) $row['movingTimeSeconds'];
            $cnt = (int) $row['count'];
            $elev = (int) $row['elevationMeters'];
            $cals = (int) $row['calories'];

            $weeklyMap[$week]['totalDistanceKm'] = round($weeklyMap[$week]['totalDistanceKm'] + $dist, 2);
            $weeklyMap[$week]['totalMovingTimeSeconds'] += $time;
            $weeklyMap[$week]['totalElevationMeters'] += $elev;
            $weeklyMap[$week]['totalCalories'] += $cals;

            $avgPaceSec = $dist > 0 ? (int) round($time / $dist) : 0;
            $weeklyMap[$week]['bySport'][$st] = [
                'count' => $cnt,
                'distanceKm' => $dist,
                'movingTimeSeconds' => $time,
                'elevationMeters' => $elev,
                'calories' => $cals,
                'averagePaceSecPerKm' => $avgPaceSec,
                'avgPaceFormatted' => $this->formatPace($avgPaceSec),
            ];
        }

        // 3. Monthly Aggregates
        $monthlyRows = $this->connection->executeQuery(<<<SQL
            SELECT 
                strftime('%Y-%m', startDateTime) AS month,
                sportType,
                COUNT(*) AS count,
                ROUND(SUM(distance) / 1000.0, 2) AS distanceKm,
                SUM(movingTimeInSeconds) AS movingTimeSeconds,
                SUM(elevation) AS elevationMeters,
                SUM(COALESCE(calories, 0)) AS calories
            FROM Activity
            WHERE markedForDeletion IS NOT 1
            GROUP BY month, sportType
            ORDER BY month DESC
        SQL)->fetchAllAssociative();

        $monthlyMap = [];
        foreach ($monthlyRows as $row) {
            $month = (string) $row['month'];
            if (!isset($monthlyMap[$month])) {
                $monthlyMap[$month] = [
                    'month' => $month,
                    'totalDistanceKm' => 0.0,
                    'totalMovingTimeSeconds' => 0,
                    'bySport' => [],
                ];
            }

            $st = (string) $row['sportType'];
            $dist = (float) $row['distanceKm'];
            $time = (int) $row['movingTimeSeconds'];

            $monthlyMap[$month]['totalDistanceKm'] = round($monthlyMap[$month]['totalDistanceKm'] + $dist, 2);
            $monthlyMap[$month]['totalMovingTimeSeconds'] += $time;
            $avgPaceSec = $dist > 0 ? (int) round($time / $dist) : 0;
            $monthlyMap[$month]['bySport'][$st] = [
                'count' => (int) $row['count'],
                'distanceKm' => $dist,
                'movingTimeSeconds' => $time,
                'avgPaceFormatted' => $this->formatPace($avgPaceSec),
            ];
        }

        return [
            'totals' => [
                'totalActivities' => $totalActivities,
                'totalDistanceKm' => round($totalDistanceKm, 2),
                'totalMovingTimeSeconds' => $totalMovingTime,
                'bySport' => $bySportTotals,
            ],
            'weekly' => array_values($weeklyMap),
            'monthly' => array_values($monthlyMap),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getActivities(?string $since): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select(
            'activityId AS id',
            'name',
            'description',
            'sportType',
            'workoutType',
            'startDateTime',
            'distance AS distanceMeters',
            'elevation AS elevationMeters',
            'movingTimeInSeconds AS movingTimeSeconds',
            'elapsedTimeInSeconds AS elapsedTimeSeconds',
            'averageSpeed AS averageSpeedKmh',
            'maxSpeed AS maxSpeedKmh',
            'averageHeartRate',
            'maxHeartRate',
            'averageCadence',
            'calories',
            'polyline AS encodedPolyline',
            'deviceName'
        )
            ->from('Activity')
            ->where('markedForDeletion IS NOT 1')
            ->orderBy('startDateTime', 'DESC');

        if ($since !== null) {
            $qb->andWhere('startDateTime >= :since')->setParameter('since', $since);
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();

        $activities = [];
        foreach ($rows as $r) {
            $distM = (int) $r['distanceMeters'];
            $timeS = (int) $r['movingTimeSeconds'];
            $distKm = round($distM / 1000.0, 2);
            $secPerKm = $distKm > 0 ? (int) round($timeS / $distKm) : 0;

            $activities[] = [
                'id' => (string) $r['id'],
                'name' => (string) $r['name'],
                'description' => $r['description'] !== null ? (string) $r['description'] : null,
                'sportType' => (string) $r['sportType'],
                'workoutType' => $r['workoutType'] !== null ? (string) $r['workoutType'] : null,
                'startDateTime' => (string) $r['startDateTime'],
                'distanceMeters' => $distM,
                'distanceKm' => $distKm,
                'movingTimeSeconds' => $timeS,
                'elapsedTimeSeconds' => (int) ($r['elapsedTimeSeconds'] ?? $timeS),
                'averageSpeedKmh' => round((float) $r['averageSpeedKmh'], 2),
                'maxSpeedKmh' => round((float) $r['maxSpeedKmh'], 2),
                'averagePaceSecPerKm' => $secPerKm,
                'averagePaceFormatted' => $this->formatPace($secPerKm),
                'averageHeartRate' => $r['averageHeartRate'] !== null ? (int) $r['averageHeartRate'] : null,
                'maxHeartRate' => $r['maxHeartRate'] !== null ? (int) $r['maxHeartRate'] : null,
                'averageCadence' => $r['averageCadence'] !== null ? (int) $r['averageCadence'] : null,
                'elevationMeters' => (int) $r['elevationMeters'],
                'calories' => $r['calories'] !== null ? (int) $r['calories'] : null,
                'encodedPolyline' => $r['encodedPolyline'] !== null ? (string) $r['encodedPolyline'] : null,
                'deviceName' => $r['deviceName'] !== null ? (string) $r['deviceName'] : null,
            ];
        }

        return $activities;
    }

    #[Route(path: '/api/mobile/elevation/dem', name: 'api_mobile_elevation_dem', methods: ['POST'])]
    public function lookupDemElevation(Request $request): JsonResponse
    {
        $content = (string) $request->getContent();
        $payload = json_decode($content, true);

        $coordinates = $payload['coordinates'] ?? [];
        if (!is_array($coordinates) || empty($coordinates)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Coordinates array required'], Response::HTTP_BAD_REQUEST);
        }

        // Downsample to max 500 points for Open-Meteo DEM batch
        $count = count($coordinates);
        $step = max(1, (int) ceil($count / 500.0));
        $sampledIndices = [];
        $lats = [];
        $lngs = [];

        for ($i = 0; $i < $count; $i += $step) {
            $pt = $coordinates[$i];
            if (isset($pt[0], $pt[1])) {
                $lats[] = round((float) $pt[0], 5);
                $lngs[] = round((float) $pt[1], 5);
                $sampledIndices[] = $i;
            }
        }

        if (empty($lats)) {
            return new JsonResponse(['status' => 'error', 'message' => 'No valid coordinates'], Response::HTTP_BAD_REQUEST);
        }

        // Chunk in batches of 100 to stay well within Open-Meteo URI length limit (< 2KB per request)
        $batchSize = 100;
        $elevations = [];
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 8,
                'header' => "User-Agent: Dreeve-Runwise-Elevation/1.0\r\n"
            ]
        ]);

        for ($b = 0; $b < count($lats); $b += $batchSize) {
            $batchLats = array_slice($lats, $b, $batchSize);
            $batchLngs = array_slice($lngs, $b, $batchSize);
            $url = 'https://api.open-meteo.com/v1/elevation?latitude=' . implode(',', $batchLats) . '&longitude=' . implode(',', $batchLngs);
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp !== false) {
                $demData = json_decode($resp, true);
                if (isset($demData['elevation']) && is_array($demData['elevation'])) {
                    foreach ($demData['elevation'] as $e) {
                        $elevations[] = (float) $e;
                    }
                }
            }
        }

        if (empty($elevations)) {
            return new JsonResponse(['status' => 'error', 'message' => 'DEM lookup unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // Apply centered zero-phase moving average smoothing to eliminate DEM discretization steps
        $smoothed = [];
        $n = count($elevations);
        for ($i = 0; $i < $n; $i++) {
            $window = [];
            for ($k = max(0, $i - 2); $k <= min($n - 1, $i + 2); $k++) {
                $window[] = (float) $elevations[$k];
            }
            $smoothed[] = round(array_sum($window) / count($window), 1);
        }

        // Compute verified climbing elevation gain (1.5m hysteresis)
        $gain = 0.0;
        $ref = $smoothed[0] ?? 0.0;
        foreach ($smoothed as $alt) {
            $diff = $alt - $ref;
            if ($diff >= 1.5) {
                $gain += $diff;
                $ref = $alt;
            } elseif ($ref - $alt >= 1.5) {
                $ref = $alt;
            }
        }

        $minAlt = !empty($smoothed) ? min($smoothed) : 0.0;
        $maxAlt = !empty($smoothed) ? max($smoothed) : 0.0;

        return new JsonResponse([
            'status' => 'success',
            'elevationGainMeters' => (int) round($gain),
            'minAltitude' => $minAlt,
            'maxAltitude' => $maxAlt,
            'elevationCurve' => $smoothed
        ]);
    }

    private function formatPace(int $secPerKm): string
    {
        if ($secPerKm <= 0) {
            return '-';
        }
        $min = intdiv($secPerKm, 60);
        $sec = $secPerKm % 60;

        return sprintf('%d:%02d', $min, $sec);
    }
}
