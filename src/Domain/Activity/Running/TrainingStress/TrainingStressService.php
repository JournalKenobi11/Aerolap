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

        // 1. Fetch relevant activities across last 180 days
        $historyStartDate = $now->sub(new \DateInterval('P180D'))->format('Y-m-d');
        $rows = $this->connection->executeQuery(
            <<<SQL
                SELECT 
                    date(startDateTime) AS activityDate,
                    sportType,
                    distance / 1000.0 AS distanceKm,
                    movingTimeInSeconds,
                    averageHeartRate
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

        // Index daily totals by date
        $daily = [];
        $runDates = [];
        foreach ($rows as $r) {
            $d = (string) $r['activityDate'];
            $st = (string) $r['sportType'];
            $km = (float) $r['distanceKm'];

            if (!isset($daily[$d])) {
                $daily[$d] = ['Run' => 0.0, 'Walk' => 0.0, 'Hike' => 0.0, 'runs' => []];
            }
            $daily[$d][$st] += $km;
            if ('Run' === $st && $km > 0.0) {
                $daily[$d]['runs'][] = $km;
                $runDates[$d] = true;
            }
        }

        // 2. Compute Acute Window [T-6, T] (Rolling 7 days)
        $acuteStart = $now->sub(new \DateInterval('P6D'));
        $acuteStartStr = $acuteStart->format('Y-m-d');

        $acuteRunKm = 0.0;
        $acuteWalkKm = 0.0;
        $acuteHikeKm = 0.0;
        $acuteRuns = [];

        for ($i = 0; $i <= 6; ++$i) {
            $dStr = $acuteStart->add(new \DateInterval("P{$i}D"))->format('Y-m-d');
            if (isset($daily[$dStr])) {
                $acuteRunKm += $daily[$dStr]['Run'];
                $acuteWalkKm += $daily[$dStr]['Walk'];
                $acuteHikeKm += $daily[$dStr]['Hike'];
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

        // 3. Compute Uncoupled Chronic Baseline [T-27, T-7] (Prior 21 days)
        $chronicEnd = $now->sub(new \DateInterval('P7D'));
        $chronicStart = $now->sub(new \DateInterval('P27D'));

        $chronicRunKmSum = 0.0;
        $chronicEffKmSum = 0.0;
        $activeDaysInChronic = 0;

        for ($i = 0; $i <= 20; ++$i) {
            $dStr = $chronicStart->add(new \DateInterval("P{$i}D"))->format('Y-m-d');
            if (isset($daily[$dStr])) {
                $r = $daily[$dStr]['Run'];
                $w = $daily[$dStr]['Walk'];
                $h = $daily[$dStr]['Hike'];
                $chronicRunKmSum += $r;
                $chronicEffKmSum += ($r + $w * $walkWeight + $h * $hikeWeight);
                if ($r > 0.0 || $w > 0.0 || $h > 0.0) {
                    ++$activeDaysInChronic;
                }
            }
        }

        // Normalize 21-day prior window to 7-day weekly rate (divide by 3)
        $chronicBaseRunKm = $chronicRunKmSum / 3.0;
        $chronicBaseEffectiveKm = $chronicEffKmSum / 3.0;

        // 4. Compute Prior 30-Day Longest Run [T-37, T-7]
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

        // 5. Volume Step Evaluation (Continuous smooth formula)
        $allowedStepKm = $config->getVolumeStepFloorKm() + ($config->getVolumeStepPct() * $chronicBaseEffectiveKm);
        $volumeStepKm = $acuteEffectiveKm - $chronicBaseEffectiveKm;

        $volumeStepWatchBuffer = $config->getVolumeStepWatchBufferKm();
        if ($volumeStepKm <= $allowedStepKm) {
            $volumeStepStatus = 'steady';
        } elseif ($volumeStepKm <= ($allowedStepKm + $volumeStepWatchBuffer)) {
            $volumeStepStatus = 'watch';
        } else {
            $volumeStepStatus = 'step_up';
        }

        // 6. Long Run Step Evaluation (Flat under 10k, percentage above 10k)
        $allowedLongRunStepKm = $prior30dMaxLongKm < $config->getLongRunCutoffDistanceKm()
            ? $config->getLongRunFlatThresholdKm()
            : max($config->getLongRunFlatThresholdKm(), $config->getLongRunPercentageAboveCutoff() * $prior30dMaxLongKm);

        $longRunStepKm = $prior30dMaxLongKm > 0.0 ? ($longestRunKm - $prior30dMaxLongKm) : 0.0;
        $longRunWatchBuffer = $config->getLongRunWatchBufferKm();

        if (0.0 === $longestRunKm) {
            $longRunStatus = 'none';
        } elseif (0.0 === $prior30dMaxLongKm) {
            $longRunStatus = 'initial';
        } elseif ($longRunStepKm <= $allowedLongRunStepKm) {
            $longRunStatus = 'normal';
        } elseif ($longRunStepKm <= ($allowedLongRunStepKm + $longRunWatchBuffer)) {
            $longRunStatus = 'watch';
        } else {
            $longRunStatus = 'jump';
        }

        // 7. Cardiovascular Load & TSB (Internal Lens)
        $dateRange180 = DateRange::lastXDays($now, 180);
        $loadPerDay = $this->dailyTrainingLoad->calculateForDateRange($dateRange180);
        $trainingMetrics = TrainingMetrics::create($loadPerDay);

        $ctl = $trainingMetrics->getCurrentCtl() ?? 12.0;
        $atl = $trainingMetrics->getCurrentAtl() ?? 20.0;
        $tsb = $trainingMetrics->getCurrentTsb()?->getValue() ?? ($ctl - $atl);

        // Historical TSB percentiles over trailing 180 days
        $tsbSeries = $trainingMetrics->getTsbValuesForXLastDays(180);
        $sortedTsbs = $tsbSeries;
        sort($sortedTsbs);
        $p10Index = max(0, (int) floor(count($sortedTsbs) * $config->getTsbLowPercentile()));
        $p10Tsb = $sortedTsbs[$p10Index] ?? -13.5;
        $isTsbLow = $tsb < $p10Tsb;

        // Rank current TSB as percentile
        $lowerCount = 0;
        foreach ($tsbSeries as $v) {
            if ($v < $tsb) {
                ++$lowerCount;
            }
        }
        $tsbPercentile = count($tsbSeries) > 0 ? round(($lowerCount / count($tsbSeries)) * 100.0, 1) : 50.0;

        // 8. Subjective Soreness Logs & Overrides
        $latestSubjective = $this->subjectiveLogRepository->findLatest();
        $recentSorenessScore = $latestSubjective ? (int) $latestSubjective['sorenessScore'] : 0;
        $recentSorenessLocation = $latestSubjective ? (string) $latestSubjective['location'] : 'None';

        $hasSorenessOverride = $recentSorenessScore >= $config->getSubjectiveOverrideScore();
        $hasSorenessCaution = $recentSorenessScore >= $config->getSubjectiveCautionScore() && !$hasSorenessOverride;
        $hasConsecutiveAlert = $this->subjectiveLogRepository->hasConsecutiveSoreness(
            $config->getSubjectiveCautionScore(),
            $config->getSubjectiveConsecutiveDaysTrigger()
        );

        // 9. Insufficient History Guard
        $recentRunDatesCount = count($runDates);
        $priorRunDates = array_filter(array_keys($runDates), fn (string $d): bool => $d < $acuteStartStr);
        $gapDays = 0;
        if (!empty($priorRunDates)) {
            $lastPriorRun = max($priorRunDates);
            $gapDays = (int) $acuteStart->diff(new \DateTimeImmutable($lastPriorRun))->days;
        } else {
            $gapDays = 999;
        }

        $isInsufficientHistory = $recentRunDatesCount < 5 || $gapDays > $config->getInsufficientHistoryDays() || $chronicBaseEffectiveKm < 3.0;

        // 10. Taper & Post-Race Guards
        $targetRaceDateStr = $config->getTargetRaceDate();
        $isTapering = false;
        $isPostRace = false;
        $daysToRace = null;

        if ($targetRaceDateStr) {
            try {
                $raceDate = new \DateTimeImmutable($targetRaceDateStr);
                $diffDays = (int) $now->diff($raceDate)->format('%r%a');
                $daysToRace = $diffDays;

                // Taper Guard: only if within taper window before race AND acute volume is actually lower than baseline
                if ($diffDays >= 0 && $diffDays <= $config->getTaperWindowDays()) {
                    if ($acuteEffectiveKm <= ($chronicBaseEffectiveKm * 0.95)) {
                        $isTapering = true;
                    }
                } elseif ($diffDays < 0 && abs($diffDays) <= $config->getPostRaceRecoveryDays()) {
                    $isPostRace = true;
                }
            } catch (\Throwable) {
            }
        }

        // 11. Determine Master Status Badge & Categorical Label
        if ($hasSorenessOverride) {
            $statusBadge = [
                'code' => 'soreness_override',
                'label' => 'Subjective Soreness Override',
                'color' => 'bg-red-500 text-white border-red-600',
                'pillClass' => 'bg-red-100 text-red-800 border-red-200',
                'description' => sprintf('Reported discomfort (%s: %d/10) overrides mathematical models.', $recentSorenessLocation, $recentSorenessScore),
            ];
            $guidanceHeadline = 'Subjective Discomfort Override';
            $guidanceText = sprintf(
                'You reported subjective soreness (%s: %d/10). Cut back or skip running today. If pain is sharp, localized, accompanied by swelling, worsening, or lasting more than a few days, consult a qualified clinician or sports physiotherapist.',
                $recentSorenessLocation,
                $recentSorenessScore
            );
        } elseif ($hasConsecutiveAlert) {
            $statusBadge = [
                'code' => 'persistent_soreness',
                'label' => 'Persistent Soreness Flag',
                'color' => 'bg-amber-500 text-white border-amber-600',
                'pillClass' => 'bg-amber-100 text-amber-800 border-amber-200',
                'description' => 'Soreness recorded at 3+ for 3 consecutive days.',
            ];
            $guidanceHeadline = 'Persistent Low-Grade Discomfort';
            $guidanceText = 'Discomfort has persisted across 3 or more consecutive check-ins. Do not push through lingering tightness; substitute with low-impact cross training or extra rest before it develops into an acute strain.';
        } elseif ($isInsufficientHistory) {
            $statusBadge = [
                'code' => 'insufficient_history',
                'label' => 'Not Enough History',
                'color' => 'bg-gray-500 text-white border-gray-600',
                'pillClass' => 'bg-gray-100 text-gray-700 border-gray-200',
                'description' => 'Awaiting 21 days of continuous logging following a break to re-establish baseline.',
            ];
            $guidanceHeadline = 'Re-establishing Training Baseline';
            $guidanceText = 'You are resuming running following a training break. Workload baseline is still calibrating. Focus on comfortable conversational pacing and conservative distance steps.';
        } elseif ($isTapering) {
            $statusBadge = [
                'code' => 'tapering',
                'label' => 'Active Race Taper',
                'color' => 'bg-blue-600 text-white border-blue-700',
                'pillClass' => 'bg-blue-100 text-blue-800 border-blue-200',
                'description' => sprintf('Pre-race volume shedding in progress (%d days to %s).', $daysToRace, $config->getTargetDistanceMeters() / 1000 . 'K'),
            ];
            $guidanceHeadline = sprintf('Race Taper in Progress (%d Days to Go)', $daysToRace);
            $guidanceText = sprintf(
                'Volume reduction is intentional and expected. Your rolling volume is down %.1f km from peak base to allow muscle glycogen saturation and tissue recovery while keeping neuromuscular legs sharp.',
                abs($volumeStepKm)
            );
        } elseif ($isPostRace) {
            $statusBadge = [
                'code' => 'post_race',
                'label' => 'Post-Race Recovery',
                'color' => 'bg-purple-600 text-white border-purple-700',
                'pillClass' => 'bg-purple-100 text-purple-800 border-purple-200',
                'description' => 'Post-race adaptation window. Step thresholds temporarily relaxed.',
            ];
            $guidanceHeadline = 'Post-Race Tissue Recovery';
            $guidanceText = 'You recently completed your target event. Prioritize low-impact active recovery walks and easy recovery runs as your baseline resets.';
        } elseif ($hasSorenessCaution) {
            $statusBadge = [
                'code' => 'caution',
                'label' => 'Soreness Caution (Watch)',
                'color' => 'bg-amber-500 text-white border-amber-600',
                'pillClass' => 'bg-amber-100 text-amber-800 border-amber-200',
                'description' => sprintf('Mild soreness reported (%s: %d/10).', $recentSorenessLocation, $recentSorenessScore),
            ];
            $guidanceHeadline = 'Mild Discomfort Noted';
            $guidanceText = sprintf(
                'Mild soreness reported (%s: %d/10). Extend dynamic warmup, avoid high-intensity intervals or hard downhill sprints, and monitor how the tissue warms up.',
                $recentSorenessLocation,
                $recentSorenessScore
            );
        } elseif ('step_up' === $volumeStepStatus || 'jump' === $longRunStatus) {
            $statusBadge = [
                'code' => 'step_up',
                'label' => 'Significant Volume Step',
                'color' => 'bg-amber-600 text-white border-amber-700',
                'pillClass' => 'bg-amber-100 text-amber-900 border-amber-200',
                'description' => sprintf('Effective weekly step (+%.1f km) exceeds standard progression buffer.', $volumeStepKm),
            ];
            $guidanceHeadline = 'Higher Volume Step Observed';
            $guidanceText = sprintf(
                'Weekly effective volume stepped up +%.1f km above your 21-day prior baseline. Consolidate volume over the next 7–10 days before taking another distance step.',
                $volumeStepKm
            );
        } elseif ($isTsbLow) {
            $statusBadge = [
                'code' => 'high_systemic_load',
                'label' => 'High Systemic Fatigue',
                'color' => 'bg-amber-500 text-white border-amber-600',
                'pillClass' => 'bg-amber-100 text-amber-800 border-amber-200',
                'description' => sprintf('Current TSB (%.1f) sits in the bottom 10%% of your personal history.', $tsb),
            ];
            $guidanceHeadline = 'Cardiovascular Fatigue Accumulated';
            $guidanceText = sprintf(
                'Your cardiovascular stress balance (TSB %.1f) is in the bottom 10th percentile of your 6-month history. Prioritize sleep quality and keep upcoming runs in Zone 1-2 easy aerobic effort.',
                $tsb
            );
        } else {
            $statusBadge = [
                'code' => 'steady',
                'label' => 'Steady Progression',
                'color' => 'bg-emerald-600 text-white border-emerald-700',
                'pillClass' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                'description' => 'Workload and long-run steps within conservative envelopes.',
            ];
            $guidanceHeadline = 'Balanced Training Progression';
            $guidanceText = sprintf(
                'Workload is progressing steadily. Effective 7-day load (%.1f km) and longest run (%.1f km) are within balanced step tolerances of your baseline.',
                $acuteEffectiveKm,
                $longestRunKm
            );
        }

        // 12. Build History Weeks Table (Last 8 rolling 7-day intervals)
        $historyWeeks = $this->buildHistoryWeeks($now, $daily, $walkWeight, $hikeWeight, $config);

        // 13. Build Chart Data (90-day daily TSB/CTL/ATL series + weekly volume bars)
        $chartData = $this->buildChartData($now, $loadPerDay, $trainingMetrics, $historyWeeks);

        return new TrainingStressResult(
            configVersion: $config->getConfigVersion(),
            statusBadge: $statusBadge,
            isInsufficientHistory: $isInsufficientHistory,
            isTapering: $isTapering,
            isPostRace: $isPostRace,
            hasSorenessOverride: $hasSorenessOverride,
            hasSorenessCaution: $hasSorenessCaution,
            hasConsecutiveSorenessAlert: $hasConsecutiveAlert,
            subjectiveLog: $latestSubjective,
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
            athleteMaxHr: $config->getMaxHr(),
            athleteRestingHr: $config->getRestingHr(),
            hrCalcMethod: $config->getHrCalcMethod(),
            targetRaceDate: $targetRaceDateStr,
            daysToRace: $daysToRace,
            guidanceHeadline: $guidanceHeadline,
            guidanceText: $guidanceText,
            historyWeeks: $historyWeeks,
            chartData: $chartData,
        );
    }

    /**
     * @param array<string, array{Run: float, Walk: float, Hike: float, runs: list<float>}> $daily
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

            for ($i = 0; $i <= 6; ++$i) {
                $d = $wStart->add(new \DateInterval("P{$i}D"))->format('Y-m-d');
                if (isset($daily[$d])) {
                    $rKm += $daily[$d]['Run'];
                    $wKm += $daily[$d]['Walk'];
                    $hKm += $daily[$d]['Hike'];
                    foreach ($daily[$d]['runs'] as $run) {
                        $runs[] = $run;
                    }
                }
            }

            $effKm = $rKm + ($wKm * $walkWeight) + ($hKm * $hikeWeight);
            $longest = !empty($runs) ? max($runs) : 0.0;

            // Prior 21-day chronic baseline
            $cEnd = $wStart->sub(new \DateInterval('P1D'));
            $cStart = $cEnd->sub(new \DateInterval('P20D'));
            $cEffSum = 0.0;
            for ($i = 0; $i <= 20; ++$i) {
                $d = $cStart->add(new \DateInterval("P{$i}D"))->format('Y-m-d');
                if (isset($daily[$d])) {
                    $cEffSum += ($daily[$d]['Run'] + $daily[$d]['Walk'] * $walkWeight + $daily[$d]['Hike'] * $hikeWeight);
                }
            }
            $cBase = $cEffSum / 3.0;
            $step = $effKm - $cBase;

            // Prior 30-day longest run
            $p30Start = $wStart->sub(new \DateInterval('P30D'));
            $p30Runs = [];
            for ($i = 0; $i <= 29; ++$i) {
                $d = $p30Start->add(new \DateInterval("P{$i}D"))->format('Y-m-d');
                if (isset($daily[$d])) {
                    foreach ($daily[$d]['runs'] as $r) {
                        $p30Runs[] = $r;
                    }
                }
            }
            $p30Longest = !empty($p30Runs) ? max($p30Runs) : 0.0;
            $lStep = $p30Longest > 0.0 ? ($longest - $p30Longest) : 0.0;

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
                'prior30dLongestKm' => round($p30Longest, 1),
                'longRunStepKm' => round($lStep, 1),
            ];
        }

        return $weeks;
    }

    /**
     * @param array<string, mixed> $loadPerDay
     * @param list<array<string, mixed>> $historyWeeks
     * @return array<string, mixed>
     */
    private function buildChartData(
        \DateTimeImmutable $now,
        array $loadPerDay,
        TrainingMetrics $trainingMetrics,
        array $historyWeeks
    ): array {
        $daysCount = min(90, count($loadPerDay));
        $days = array_slice(array_keys($loadPerDay), -$daysCount);

        $ctlSeries = $trainingMetrics->getCtlValuesForXLastDays($daysCount);
        $atlSeries = $trainingMetrics->getAtlValuesForXLastDays($daysCount);
        $tsbSeries = $trainingMetrics->getTsbValuesForXLastDays($daysCount);

        $weeklyCategories = [];
        $weeklyRunData = [];
        $weeklyWalkData = [];
        $weeklyBaseData = [];

        foreach (array_reverse($historyWeeks) as $hw) {
            $weeklyCategories[] = $hw['label'];
            $weeklyRunData[] = $hw['runKm'];
            $weeklyWalkData[] = round($hw['effectiveKm'] - $hw['runKm'], 1); // Walk/Hike contribution
            $weeklyBaseData[] = $hw['chronicBaseKm'];
        }

        return [
            'cardioDays' => $days,
            'ctlSeries' => array_map(fn ($v) => round((float) $v, 1), $ctlSeries),
            'atlSeries' => array_map(fn ($v) => round((float) $v, 1), $atlSeries),
            'tsbSeries' => array_map(fn ($v) => round((float) $v, 1), $tsbSeries),
            'weeklyCategories' => $weeklyCategories,
            'weeklyRunData' => $weeklyRunData,
            'weeklyWalkData' => $weeklyWalkData,
            'weeklyBaseData' => $weeklyBaseData,
        ];
    }
}
