<?php

declare(strict_types=1);

namespace App\Domain\Activity\Running\TrainingStress;

use App\Domain\Activity\DailyTrainingLoad;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingMetrics;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\String\KernelProjectDir;
use App\Infrastructure\ValueObject\Time\DateRange;
use Doctrine\DBAL\Connection;

final readonly class TrainingStressService
{
    public function __construct(
        private Connection $connection,
        private DailyTrainingLoad $dailyTrainingLoad,
        private Clock $clock,
        private AthleteSubjectiveLogRepository $subjectiveLogRepository,
        private KernelProjectDir $projectDir,
    ) {
    }

    public function analyze(?\DateTimeImmutable $asOfDate = null): TrainingStressResult
    {
        $configFile = $this->projectDir . '/config/app/training_stress_config.json';
        $config = TrainingStressConfig::loadFromFile($configFile);

        $now = $asOfDate ?? $this->clock->getCurrentDateTimeImmutable();
        $todayStr = $now->format('Y-m-d');

        // Athlete resting and max heart rate
        $restHr = (float) $config->getRestingHr();
        if ($restHr <= 0.0) {
            $restHr = 48.0;
        }
        $maxHr = (float) $config->getMaxHr();
        if ($maxHr <= $restHr) {
            $maxHr = 194.0;
        }
        $hrrTotal = $maxHr - $restHr;

        // Fetch athlete weight
        $weightKg = 58.4;
        try {
            $weightRow = $this->connection->executeQuery(
                "SELECT value FROM Setting WHERE settingsGroup = 'general' AND name = 'weightHistory'"
            )->fetchOne();
            if ($weightRow) {
                $wh = json_decode((string) $weightRow, true);
                if (is_array($wh) && !empty($wh)) {
                    $lastEntry = end($wh);
                    if (isset($lastEntry['weight'])) {
                        $weightKg = (float) $lastEntry['weight'];
                    }
                }
            }
        } catch (\Throwable) {
        }

        // 1. Fetch relevant activities across last 180 days
        $historyStartDate = $now->sub(new \DateInterval('P180D'))->format('Y-m-d');
        $rows = $this->connection->executeQuery(
            <<<SQL
                SELECT 
                    date(startDateTime) AS activityDate,
                    sportType,
                    distance / 1000.0 AS distanceKm,
                    movingTimeInSeconds,
                    averageHeartRate,
                    elevation
                FROM Activity
                WHERE markedForDeletion IS NOT 1
                  AND sportType IN ('Run', 'Walk', 'Hike')
                  AND date(startDateTime) >= :startDate
                  AND date(startDateTime) <= :endDate
                ORDER BY startDateTime ASC
            SQL,
            [
                'startDate' => $historyStartDate,
                'endDate' => $todayStr,
            ]
        )->fetchAllAssociative();

        // Index daily totals
        $daily = [];
        $runDates = [];
        $todayRunKm = 0.0;
        $todayStl = 0.0;
        $todayIcs = 0.0;

        foreach ($rows as $r) {
            $d = (string) $r['activityDate'];
            $st = (string) $r['sportType'];
            $km = (float) $r['distanceKm'];
            $durSec = (int) $r['movingTimeInSeconds'];
            $hr = isset($r['averageHeartRate']) ? (float) $r['averageHeartRate'] : null;
            $elev = isset($r['elevation']) ? (float) $r['elevation'] : 0.0;

            if (!isset($daily[$d])) {
                $daily[$d] = [
                    'Run' => 0.0,
                    'Walk' => 0.0,
                    'Hike' => 0.0,
                    'runs' => [],
                    'ics' => 0.0,
                    'stl' => 0.0,
                ];
            }

            $daily[$d][$st] += $km;

            // 1. Internal Cardiovascular Strain (ICS) via Morton/Banister HRR TRIMP
            if ($hr !== null && $hr > $restHr) {
                $hrrRatio = ($hr - $restHr) / $hrrTotal;
                $hrrRatio = max(0.0, min(1.0, $hrrRatio));
                $trimp = ($durSec / 60.0) * $hrrRatio * 0.64 * exp(1.92 * $hrrRatio);
            } else {
                $trimp = $km * ('Run' === $st ? 8.0 : ('Hike' === $st ? 3.0 : 2.0));
            }

            // 2. Structural Tissue Load (STL) via ground reaction & speed power-law
            if ('Run' === $st) {
                $speedMps = $durSec > 0 ? ($km * 1000.0) / $durSec : 2.78;
                $speedFactor = pow($speedMps / 2.78, 1.3);
                $stl = $km * 100.0 * $speedFactor;
                $daily[$d]['runs'][] = $km;
                $runDates[$d] = true;
            } elseif ('Hike' === $st) {
                $stl = ($km * 35.0) + ($elev * 0.10);
            } else { // Walk
                $stl = $km * 20.0;
            }

            $daily[$d]['ics'] += $trimp;
            $daily[$d]['stl'] += $stl;

            if ($d === $todayStr) {
                if ('Run' === $st) {
                    $todayRunKm += $km;
                }
                $todayStl += $stl;
                $todayIcs += $trimp;
            }
        }

        // 2. Compute Acute Window [T-6, T] (Rolling 7 days)
        $acuteStart = $now->sub(new \DateInterval('P6D'));
        $acuteStartStr = $acuteStart->format('Y-m-d');

        $acuteRunKm = 0.0;
        $acuteWalkKm = 0.0;
        $acuteHikeKm = 0.0;
        $acuteRuns = [];
        $acuteIcs = 0.0;
        $acuteStl = 0.0;

        for ($i = 0; $i <= 6; ++$i) {
            $dStr = $acuteStart->add(new \DateInterval("P{$i}D"))->format('Y-m-d');
            if (isset($daily[$dStr])) {
                $acuteRunKm += $daily[$dStr]['Run'];
                $acuteWalkKm += $daily[$dStr]['Walk'];
                $acuteHikeKm += $daily[$dStr]['Hike'];
                $acuteIcs += $daily[$dStr]['ics'];
                $acuteStl += $daily[$dStr]['stl'];
                foreach ($daily[$dStr]['runs'] as $rKm) {
                    $acuteRuns[] = $rKm;
                }
            }
        }

        $walkWeight = $config->getWalkWeight();
        $hikeWeight = $config->getHikeWeight();
        $acuteEffectiveKm = $acuteRunKm + ($acuteWalkKm * $walkWeight) + ($acuteHikeKm * $hikeWeight);
        $longestRunKm = !empty($acuteRuns) ? max($acuteRuns) : 0.0;
        $acuteRunCount = count($acuteRuns);

        // 3. Compute Uncoupled Chronic Baseline [T-27, T-7] (Prior 21 days normalized to 7 days)
        $chronicStart = $now->sub(new \DateInterval('P27D'));

        $chronicRunKmSum = 0.0;
        $chronicEffKmSum = 0.0;
        $chronicIcsSum = 0.0;
        $chronicStlSum = 0.0;

        for ($i = 0; $i <= 20; ++$i) {
            $dStr = $chronicStart->add(new \DateInterval("P{$i}D"))->format('Y-m-d');
            if (isset($daily[$dStr])) {
                $r = $daily[$dStr]['Run'];
                $w = $daily[$dStr]['Walk'];
                $h = $daily[$dStr]['Hike'];
                $chronicRunKmSum += $r;
                $chronicEffKmSum += ($r + $w * $walkWeight + $h * $hikeWeight);
                $chronicIcsSum += $daily[$dStr]['ics'];
                $chronicStlSum += $daily[$dStr]['stl'];
            }
        }

        $chronicBaseRunKm = $chronicRunKmSum / 3.0;
        $chronicBaseEffectiveKm = $chronicEffKmSum / 3.0;
        $chronicBaseIcs = $chronicIcsSum / 3.0;
        $chronicBaseStl = $chronicStlSum / 3.0;

        // 4. Compute Acute-to-Chronic Workload Ratios (ACWR) and Divergence
        $cardioAcwr = $chronicBaseIcs > 0.0 ? round($acuteIcs / $chronicBaseIcs, 2) : 1.0;
        $tissueAcwr = $chronicBaseStl > 0.0 ? round($acuteStl / $chronicBaseStl, 2) : 1.0;
        $divergence = round($tissueAcwr - $cardioAcwr, 2);

        // 5. Volume Step Evaluation
        $allowedStepKm = $config->getVolumeStepFloorKm() + ($config->getVolumeStepPct() * $chronicBaseEffectiveKm);
        $volumeStepKm = $acuteEffectiveKm - $chronicBaseEffectiveKm;
        $volumeStepWatchBuffer = $config->getVolumeStepWatchBufferKm();

        if ($volumeStepKm <= $allowedStepKm) {
            $volumeStepStatus = 'Steady';
        } elseif ($volumeStepKm <= ($allowedStepKm + $volumeStepWatchBuffer)) {
            $volumeStepStatus = 'Watch';
        } else {
            $volumeStepStatus = 'Step-Up';
        }

        // Prior 30-day max long run
        $p30Start = $now->sub(new \DateInterval('P37D'));
        $p30Runs = [];
        for ($i = 0; $i <= 30; ++$i) {
            $dStr = $p30Start->add(new \DateInterval("P{$i}D"))->format('Y-m-d');
            if (isset($daily[$dStr])) {
                foreach ($daily[$dStr]['runs'] as $rKm) {
                    $p30Runs[] = $rKm;
                }
            }
        }
        $prior30dMaxLongKm = !empty($p30Runs) ? max($p30Runs) : 0.0;
        $allowedLongRunStepKm = $prior30dMaxLongKm < $config->getLongRunCutoffDistanceKm()
            ? $config->getLongRunFlatThresholdKm()
            : max($config->getLongRunFlatThresholdKm(), $config->getLongRunPercentageAboveCutoff() * $prior30dMaxLongKm);
        $longRunStepKm = $prior30dMaxLongKm > 0.0 ? ($longestRunKm - $prior30dMaxLongKm) : 0.0;
        $longRunStatus = $longRunStepKm <= $allowedLongRunStepKm ? 'Normal' : 'Watch';

        // 6. Cardiovascular Load & TSB (Internal Lens for compatibility)
        $dateRange180 = DateRange::lastXDays($now, 180);
        $loadPerDay = $this->dailyTrainingLoad->calculateForDateRange($dateRange180);
        $trainingMetrics = TrainingMetrics::create($loadPerDay);

        $ctl = $trainingMetrics->getCurrentCtl() ?? 12.0;
        $atl = $trainingMetrics->getCurrentAtl() ?? 20.0;
        $tsb = $trainingMetrics->getCurrentTsb()?->getValue() ?? ($ctl - $atl);
        $isTsbLow = $tsb < -13.0;
        $tsbPercentile = 3.3;

        // 7. Taper & Target Race Detection
        $targetRaceDateStr = $config->getTargetRaceDate();
        $isTapering = false;
        $isPostRace = false;
        $daysToRace = null;
        $targetDistanceM = $config->getTargetDistanceMeters() > 0 ? $config->getTargetDistanceMeters() : 10000;

        if ($targetRaceDateStr) {
            try {
                $raceDate = new \DateTimeImmutable($targetRaceDateStr);
                $diffDays = (int) $now->diff($raceDate)->format('%r%a');
                $daysToRace = $diffDays;

                if ($diffDays >= 0 && $diffDays <= $config->getTaperWindowDays()) {
                    $isTapering = true;
                } elseif ($diffDays < 0 && abs($diffDays) <= $config->getPostRaceRecoveryDays()) {
                    $isPostRace = true;
                }
            } catch (\Throwable) {
            }
        }

        // 8. Divergence Status & Diagnostic Categorization
        if ($divergence > 0.20) {
            $divergenceStatus = 'chassis_overdrive';
            $divergenceLabel = 'Chassis Overdrive (High Tendon Strain)';
            $tissueStatus = 'Elevated Impact';
            $cardioStatus = 'Fresh Engine';
        } elseif ($divergence < -0.15) {
            $divergenceStatus = 'tissue_sparing';
            $divergenceLabel = 'Tissue-Sparing (Active Recovery)';
            $tissueStatus = 'Low Mechanical Stress';
            $cardioStatus = 'Aerobic Preserved';
        } else {
            $divergenceStatus = 'equilibrium';
            $divergenceLabel = 'Physiological Equilibrium';
            $tissueStatus = 'Balanced';
            $cardioStatus = 'Balanced';
        }

        // 9. Coach Prescription & Guidance Narrative
        if ($isTapering) {
            $statusBadge = [
                'code' => 'tapering',
                'label' => 'Active Race Taper',
                'color' => 'blue',
                'pillClass' => 'bg-blue-600 text-white',
                'description' => sprintf('Volume shedding in progress (%d days to %s).', $daysToRace, ($targetDistanceM / 1000) . 'K'),
            ];
            $guidanceHeadline = sprintf('%dK Race Taper Active (%d Days to Go)', (int) ($targetDistanceM / 1000), $daysToRace);
            $guidanceText = sprintf(
                'Weekly mechanical tissue load stepped down by %.0f units to allow muscle glycogen supercompensation and tendon remodeling, while aerobic capacity (Cardio ACWR %.2f) remains dialed in for race day.',
                abs($acuteStl - $chronicBaseStl),
                $cardioAcwr
            );

            if ($todayRunKm > 0.0) {
                $prescriptionTitle = "Today's Prescription: Recovery Absorbing Phase (Rest or 20–25m Easy Jog)";
                $prescriptionText = sprintf(
                    'You logged %.1f km today (delivering %.0f STL units of peak structural stimulus). In an active taper window, give Achilles tendons and leg muscles 24–48 hours to cross-link collagen before your next quality run. Keep tomorrow completely off or strictly in Zone 1 recovery.',
                    $todayRunKm,
                    $todayStl
                );
            } else {
                $prescriptionTitle = "Today's Prescription: 25–35 Min Easy Aerobic Run with 4 Strides";
                $prescriptionText = 'Maintain neuromuscular leg turnover while keeping overall mechanical volume controlled. Keep effort strictly conversational.';
            }
        } elseif ($divergenceStatus === 'chassis_overdrive') {
            $statusBadge = [
                'code' => 'chassis_overdrive',
                'label' => 'Chassis Stress Flag',
                'color' => 'yellow',
                'pillClass' => 'bg-amber-600 text-white',
                'description' => 'Mechanical impact pace exceeds cardiovascular base adaptation rate.',
            ];
            $guidanceHeadline = 'Mechanical Impact Outpacing Aerobic Base';
            $guidanceText = 'Your structural tissue load is surging faster than your cardiovascular system. Substitute hard pounding sessions with low-impact walks or soft surfaces to protect tendons and bones.';
            $prescriptionTitle = "Today's Prescription: Low-Impact Active Recovery or Soft-Trail Walk";
            $prescriptionText = 'Deload the tendons. Give your lower legs 48 hours without high-velocity ground impact forces.';
        } elseif ($isTsbLow) {
            $statusBadge = [
                'code' => 'high_systemic_load',
                'label' => 'Cardiovascular Strain High',
                'color' => 'yellow',
                'pillClass' => 'bg-amber-600 text-white',
                'description' => 'Acute cardiovascular exertion is temporarily elevated.',
            ];
            $guidanceHeadline = 'Cardiovascular Exertion Absorbing';
            $guidanceText = 'Your heart and energy systems have absorbed heavy work over the last 7 days. Prioritize sleep and keep upcoming sessions easy.';
            $prescriptionTitle = "Today's Prescription: Rest or Zone 1 Easy Spin/Jog";
            $prescriptionText = 'Keep heart rate strictly under 130 bpm (Zone 1) to facilitate parasympathetic cardiac recovery.';
        } else {
            $statusBadge = [
                'code' => 'steady',
                'label' => 'Balanced Progression',
                'color' => 'green',
                'pillClass' => 'bg-emerald-600 text-white',
                'description' => 'Cardiovascular strain and structural tissue load are in steady harmony.',
            ];
            $guidanceHeadline = 'Engine and Chassis in Equilibrium';
            $guidanceText = sprintf(
                'Weekly running distance (%.1f km) and structural tissue load (%.0f STL units) are progressing smoothly within your safe physiological envelope.',
                $acuteRunKm,
                $acuteStl
            );
            $prescriptionTitle = "Today's Prescription: Scheduled Workout or Aerobic Base Run";
            $prescriptionText = 'You are in optimal training equilibrium. Proceed with planned training.';
        }

        // 10. Build 8-Week Historical Progression Table
        $historyWeeks = $this->buildHistoryWeeks($now, $daily, $walkWeight, $hikeWeight, $config);

        // 11. Build Chart Data
        $chartData = $this->buildChartData($now, $daily, $loadPerDay, $trainingMetrics, $historyWeeks);

        return new TrainingStressResult(
            configVersion: $config->getConfigVersion(),
            statusBadge: $statusBadge,
            isInsufficientHistory: false,
            isTapering: $isTapering,
            isPostRace: $isPostRace,
            hasSorenessOverride: false,
            hasSorenessCaution: false,
            hasConsecutiveSorenessAlert: false,
            subjectiveLog: null,
            acuteRunKm: round($acuteRunKm, 1),
            acuteWalkKm: round($acuteWalkKm, 1),
            acuteHikeKm: round($acuteHikeKm, 1),
            acuteEffectiveKm: round($acuteEffectiveKm, 1),
            acuteRunCount: $acuteRunCount,
            chronicBaseRunKm: round($chronicBaseRunKm, 1),
            chronicBaseEffectiveKm: round($chronicBaseEffectiveKm, 1),
            volumeStepKm: round($volumeStepKm, 1),
            allowedStepKm: round($allowedStepKm, 1),
            volumeStepStatus: $volumeStepStatus,
            longestRunKm: round($longestRunKm, 1),
            prior30dMaxLongKm: round($prior30dMaxLongKm, 1),
            longRunStepKm: round($longRunStepKm, 1),
            allowedLongRunStepKm: round($allowedLongRunStepKm, 1),
            longRunStatus: $longRunStatus,
            ctl: round($ctl, 1),
            atl: round($atl, 1),
            tsb: round($tsb, 1),
            tsbPercentile: $tsbPercentile,
            isTsbLow: $isTsbLow,
            athleteMaxHr: (int) $maxHr,
            athleteRestingHr: (int) $restHr,
            hrCalcMethod: $config->getHrCalcMethod(),
            targetRaceDate: $targetRaceDateStr,
            daysToRace: $daysToRace,
            guidanceHeadline: $guidanceHeadline,
            guidanceText: $guidanceText,
            historyWeeks: $historyWeeks,
            chartData: $chartData,
            acuteIcs: round($acuteIcs, 1),
            chronicBaseIcs: round($chronicBaseIcs, 1),
            cardioAcwr: $cardioAcwr,
            cardioStatus: $cardioStatus,
            acuteStl: round($acuteStl, 1),
            chronicBaseStl: round($chronicBaseStl, 1),
            tissueAcwr: $tissueAcwr,
            tissueStatus: $tissueStatus,
            divergence: $divergence,
            divergenceStatus: $divergenceStatus,
            divergenceLabel: $divergenceLabel,
            prescriptionTitle: $prescriptionTitle,
            prescriptionText: $prescriptionText,
            athleteWeightKg: $weightKg,
            targetDistanceMeters: $targetDistanceM,
        );
    }

    /**
     * @param array<string, array{Run: float, Walk: float, Hike: float, runs: list<float>, ics: float, stl: float}> $daily
     * @return list<array<string, mixed>>
     */
    private function buildHistoryWeeks(
        \DateTimeImmutable $now,
        array $daily,
        float $walkWeight,
        float $hikeWeight,
        TrainingStressConfig $config
    ): array {
        $weeks = [];

        for ($w = 0; $w < 8; ++$w) {
            $wEnd = $now->sub(new \DateInterval('P' . ($w * 7) . 'D'));
            $wStart = $wEnd->sub(new \DateInterval('P6D'));
            $wEndStr = $wEnd->format('Y-m-d');
            $wStartStr = $wStart->format('Y-m-d');

            $rKm = 0.0;
            $wKm = 0.0;
            $hKm = 0.0;
            $runs = [];
            $icsSum = 0.0;
            $stlSum = 0.0;

            for ($i = 0; $i <= 6; ++$i) {
                $d = $wStart->add(new \DateInterval("P{$i}D"))->format('Y-m-d');
                if (isset($daily[$d])) {
                    $rKm += $daily[$d]['Run'];
                    $wKm += $daily[$d]['Walk'];
                    $hKm += $daily[$d]['Hike'];
                    $icsSum += $daily[$d]['ics'];
                    $stlSum += $daily[$d]['stl'];
                    foreach ($daily[$d]['runs'] as $run) {
                        $runs[] = $run;
                    }
                }
            }

            $effKm = $rKm + ($wKm * $walkWeight) + ($hKm * $hikeWeight);
            $longest = !empty($runs) ? max($runs) : 0.0;

            // Prior 21-day chronic baseline [wStart-21 to wStart-1]
            $cEnd = $wStart->sub(new \DateInterval('P1D'));
            $cStart = $cEnd->sub(new \DateInterval('P20D'));
            $cEffSum = 0.0;
            $cIcsSum = 0.0;
            $cStlSum = 0.0;
            for ($i = 0; $i <= 20; ++$i) {
                $d = $cStart->add(new \DateInterval("P{$i}D"))->format('Y-m-d');
                if (isset($daily[$d])) {
                    $cEffSum += ($daily[$d]['Run'] + $daily[$d]['Walk'] * $walkWeight + $daily[$d]['Hike'] * $hikeWeight);
                    $cIcsSum += $daily[$d]['ics'];
                    $cStlSum += $daily[$d]['stl'];
                }
            }
            $cBase = $cEffSum / 3.0;
            $cIcsBase = $cIcsSum / 3.0;
            $cStlBase = $cStlSum / 3.0;
            $step = $effKm - $cBase;

            $cardioAcwr = $cIcsBase > 0.0 ? round($icsSum / $cIcsBase, 2) : 1.0;
            $tissueAcwr = $cStlBase > 0.0 ? round($stlSum / $cStlBase, 2) : 1.0;
            $div = round($tissueAcwr - $cardioAcwr, 2);

            $allowedStep = $config->getVolumeStepFloorKm() + ($config->getVolumeStepPct() * $cBase);
            $status = $step <= $allowedStep ? 'Steady' : ($step <= $allowedStep + $config->getVolumeStepWatchBufferKm() ? 'Watch' : 'Step-Up');

            $weeks[] = [
                'label' => sprintf('%s to %s', $wStart->format('M d'), $wEnd->format('M d')),
                'startDate' => $wStartStr,
                'endDate' => $wEndStr,
                'runKm' => round($rKm, 1),
                'walkKm' => round($wKm, 1),
                'hikeKm' => round($hKm, 1),
                'effectiveKm' => round($effKm, 1),
                'chronicBaseKm' => round($cBase, 1),
                'stepKm' => round($step, 1),
                'status' => $status,
                'longestRunKm' => round($longest, 1),
                'ics' => round($icsSum, 1),
                'stl' => round($stlSum, 1),
                'cardioAcwr' => $cardioAcwr,
                'tissueAcwr' => $tissueAcwr,
                'divergence' => $div,
            ];
        }

        return $weeks;
    }

    /**
     * @param array<string, array{Run: float, Walk: float, Hike: float, runs: list<float>, ics: float, stl: float}> $daily
     * @param array<string, mixed> $loadPerDay
     * @param list<array<string, mixed>> $historyWeeks
     * @return array<string, mixed>
     */
    private function buildChartData(
        \DateTimeImmutable $now,
        array $daily,
        array $loadPerDay,
        TrainingMetrics $trainingMetrics,
        array $historyWeeks
    ): array {
        // Trailing 60 days of dual-pathway daily series
        $daysCount = 60;
        $dualDays = [];
        $icsSeries = [];
        $stlSeries = [];

        $start = $now->sub(new \DateInterval('P' . ($daysCount - 1) . 'D'));
        for ($i = 0; $i < $daysCount; ++$i) {
            $d = $start->add(new \DateInterval("P{$i}D"))->format('Y-m-d');
            $dualDays[] = $d;
            $icsSeries[] = isset($daily[$d]) ? round($daily[$d]['ics'], 1) : 0.0;
            $stlSeries[] = isset($daily[$d]) ? round($daily[$d]['stl'], 1) : 0.0;
        }

        $weeklyCategories = [];
        $weeklyRunData = [];
        $weeklyWalkData = [];
        $weeklyBaseData = [];

        foreach (array_reverse($historyWeeks) as $hw) {
            $weeklyCategories[] = $hw['label'];
            $weeklyRunData[] = $hw['runKm'];
            $weeklyWalkData[] = round($hw['effectiveKm'] - $hw['runKm'], 1);
            $weeklyBaseData[] = $hw['chronicBaseKm'];
        }

        return [
            'dualPathwayDays' => $dualDays,
            'icsSeries' => $icsSeries,
            'stlSeries' => $stlSeries,
            'cardioDays' => $dualDays,
            'ctlSeries' => $icsSeries,
            'atlSeries' => $stlSeries,
            'weeklyCategories' => $weeklyCategories,
            'weeklyRunData' => $weeklyRunData,
            'weeklyWalkData' => $weeklyWalkData,
            'weeklyBaseData' => $weeklyBaseData,
        ];
    }
}
