<?php

declare(strict_types=1);

namespace App\Domain\Activity\Running;

use App\Infrastructure\ValueObject\String\KernelProjectDir;

final readonly class RunPlanService
{
    private string $storageDir;
    private string $configFile;
    private string $customPlanFile;

    public function __construct(KernelProjectDir $projectDir)
    {
        $this->storageDir = rtrim((string) $projectDir, '/').'/storage/database';
        $this->configFile = $this->storageDir.'/runwise_coach_config.json';
        $this->customPlanFile = $this->storageDir.'/runwise_custom_plan.json';
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        if (file_exists($this->configFile)) {
            $data = json_decode((string) file_get_contents($this->configFile), true);
            if (is_array($data)) {
                return $data;
            }
        }

        return ['gmail' => 'aashaykdgr@gmail.com'];
    }

    public function saveGmail(string $gmail): void
    {
        $config = $this->getConfig();
        $config['gmail'] = trim($gmail);
        @file_put_contents($this->configFile, json_encode($config, JSON_PRETTY_PRINT));
    }

    public function resetPlan(): void
    {
        if (file_exists($this->customPlanFile)) {
            @unlink($this->customPlanFile);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getActivePlanData(): array
    {
        if (file_exists($this->customPlanFile)) {
            $custom = json_decode((string) file_get_contents($this->customPlanFile), true);
            if (is_array($custom) && !empty($custom['weeks'])) {
                return $custom;
            }
        }

        return $this->getDefaultPlanData();
    }

    /**
     * Parse any standard .ics content into Runwise calendar structure
     *
     * @return array<string, mixed>
     */
    public function parseIcsContent(string $content): array
    {
        $content = str_replace(["\r\n ", "\n "], '', $content);
        $lines = explode("\n", str_replace("\r", '', $content));

        $events = [];
        $current = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === 'BEGIN:VEVENT') {
                $current = ['exercises' => []];
                continue;
            }
            if ($line === 'END:VEVENT') {
                if ($current && isset($current['date'])) {
                    $events[] = $current;
                }
                $current = null;
                continue;
            }
            if ($current === null) {
                continue;
            }

            if (preg_match('/^DTSTART(?:;[^:]+)?:(\d{8})/i', $line, $m)) {
                $d = $m[1];
                $current['date'] = substr($d, 0, 4).'-'.substr($d, 4, 2).'-'.substr($d, 6, 2);
            } elseif (preg_match('/^SUMMARY:(.*)$/i', $line, $m)) {
                $current['summary'] = stripslashes(trim($m[1]));
            } elseif (preg_match('/^DESCRIPTION:(.*)$/i', $line, $m)) {
                $current['description'] = str_replace(['\\n', '\\,'], ["\n", ','], stripslashes(trim($m[1])));
            }
        }

        if (empty($events)) {
            return [];
        }

        usort($events, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

        $weeks = [];
        $weekIndex = 1;
        $currentWeekDays = [];
        $weekStartDate = null;

        foreach ($events as $idx => $ev) {
            $dt = new \DateTimeImmutable($ev['date']);
            $dayName = $dt->format('D');
            $dayNumber = $dt->format('d');

            $title = $ev['summary'] ?? 'Workout';
            $category = 'easy';
            $badge = 'WORKOUT';
            $lower = strtolower($title.' '.($ev['description'] ?? ''));

            if (str_contains($lower, 'race')) {
                $category = 'race';
                $badge = 'RACE DAY';
            } elseif (str_contains($lower, 'pace lock') || str_contains($lower, 'interval') || str_contains($lower, 'tempo') || str_contains($lower, 'speed') || str_contains($lower, 'threshold')) {
                $category = 'workout';
                $badge = 'PACE LOCK';
            } elseif (str_contains($lower, 'long run') || str_contains($lower, 'long')) {
                $category = 'long';
                $badge = 'LONG RUN';
            } elseif (str_contains($lower, 'rest')) {
                $category = 'rest';
                $badge = 'REST';
            } elseif (str_contains($lower, 'walk') || str_contains($lower, 'recovery')) {
                $category = 'walk';
                $badge = 'RECOVERY';
            } elseif (str_contains($lower, 'strength')) {
                $category = 'strength';
                $badge = 'STRENGTH';
            }

            $dist = '0.0 km';
            if (preg_match('/(\d+(?:\.\d+)?)\s*km/i', $title.' '.($ev['description'] ?? ''), $dm)) {
                $dist = $dm[1].' km';
            }

            $pace = 'Target Pace';
            if (preg_match('/(\d+:\d+(?:\s*–\s*\d+:\d+)?\s*(?:\/\s*km|min\/km)?)/i', $ev['description'] ?? '', $pm)) {
                $pace = $pm[1];
            }

            $targetHr = 'Zone 2';
            if (preg_match('/(\d+(?:\s*–\s*\d+)?\s*bpm)/i', $ev['description'] ?? '', $hm)) {
                $targetHr = $hm[1];
            }

            $exercises = [];
            if ($category === 'easy' || $category === 'workout') {
                $exercises = [
                    ['name' => 'Eccentric Heel Drops (Straight Leg)', 'reps' => '3 sets × 8–10 reps / leg', 'type' => 'Calf Protocol'],
                    ['name' => 'Bent-Knee Soleus Calf Raises', 'reps' => '3 sets × 15 reps (2kg dumbbells)', 'type' => 'Calf Protocol'],
                    ['name' => 'Wall Isometric Single-Leg Hold', 'reps' => '3 sets × 25 sec / leg', 'type' => 'Calf Protocol'],
                ];
            } elseif ($category === 'rest') {
                $exercises = [
                    ['name' => 'Gentle Wall Calf Stretch', 'reps' => '2 sets × 40 sec / leg', 'type' => 'Stretching'],
                    ['name' => 'Bent-Knee Soleus Stretch', 'reps' => '2 sets × 40 sec / leg', 'type' => 'Stretching'],
                ];
            }

            $currentWeekDays[] = [
                'date' => $ev['date'],
                'dayName' => $dayName,
                'dayNumber' => $dayNumber,
                'title' => $title,
                'badge' => $badge,
                'category' => $category,
                'distance' => $dist,
                'pace' => $pace,
                'targetHr' => $targetHr,
                'description' => $ev['description'] ?? 'Imported from .ics calendar.',
                'exercises' => $exercises,
            ];

            if ($weekStartDate === null) {
                $weekStartDate = $dt;
            }

            if (count($currentWeekDays) === 7 || $dayName === 'Sun' || $idx === count($events) - 1) {
                $weeks[] = [
                    'number' => $weekIndex++,
                    'title' => 'Imported Week '.($weekIndex - 1),
                    'dates' => $weekStartDate->format('M d').' – '.$dt->format('M d'),
                    'targetDistance' => 'Custom .ics Plan',
                    'focus' => 'Workouts imported from custom calendar file.',
                    'projectedTsb' => 'Imported Schedule',
                    'days' => $currentWeekDays,
                ];
                $currentWeekDays = [];
                $weekStartDate = null;
            }
        }

        return [
            'goalTitle' => 'Imported Custom Plan (.ics)',
            'targetPace' => 'Custom Pace',
            'targetTime' => 'Custom Schedule',
            'currentCtl' => '30.4',
            'currentAtl' => '44.7',
            'currentTsb' => '-14.3',
            'targetRaceTsb' => '+7.5',
            'acwr' => '1.47',
            'weeks' => $weeks,
        ];
    }

    public function saveCustomPlan(array $plan): void
    {
        @file_put_contents($this->customPlanFile, json_encode($plan, JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaultPlanData(): array
    {
        $weeks = [
            [
                'number' => 1,
                'title' => 'Week 1: Calibration & Peak Build',
                'dates' => 'Nov 02 – Nov 08',
                'targetDistance' => '19.5 km Running + 3.0 km Walking',
                'focus' => 'Lock 6:15/km motor engram. Peak long run capped at 8.5 km.',
                'projectedTsb' => '-4.6 (Controlled Load)',
                'days' => [
                    [
                        'date' => '2026-11-02',
                        'dayName' => 'Mon',
                        'dayNumber' => '02',
                        'title' => 'Complete Rest Day',
                        'badge' => 'REST',
                        'category' => 'rest',
                        'distance' => '0.0 km',
                        'pace' => 'Rest',
                        'targetHr' => '< 75 bpm',
                        'description' => 'Zero running. Allow cardiovascular system and calves to recover from late October efforts. Gentle post-shower wall calf stretch only.',
                        'exercises' => [
                            ['name' => 'Straight-Leg Wall Calf Stretch', 'reps' => '2 sets × 40 sec / leg', 'type' => 'Stretching'],
                            ['name' => 'Bent-Knee Soleus Stretch', 'reps' => '2 sets × 40 sec / leg', 'type' => 'Stretching'],
                        ],
                    ],
                    [
                        'date' => '2026-11-03',
                        'dayName' => 'Tue',
                        'dayNumber' => '03',
                        'title' => 'Easy Aerobic Base Run',
                        'badge' => 'EASY RUN',
                        'category' => 'easy',
                        'distance' => '4.5 km',
                        'pace' => '6:50 – 7:05 / km',
                        'targetHr' => '135 – 142 bpm (Zone 2)',
                        'description' => 'Strictly relaxed aerobic running. Keep cadence high (~168 spm) and effort very light. Followed immediately by calf eccentric protocol.',
                        'exercises' => [
                            ['name' => 'Eccentric Heel Drops (Straight Leg)', 'reps' => '3 sets × 8–10 reps / leg (3-sec lowering)', 'type' => 'Calf Protocol'],
                            ['name' => 'Bent-Knee Calf Raises (Soleus)', 'reps' => '3 sets × 15 reps (Hold 2kg dumbbells)', 'type' => 'Calf Protocol'],
                            ['name' => 'Single-Leg Wall Isometric Hold', 'reps' => '3 sets × 25 sec hold / leg', 'type' => 'Calf Protocol'],
                        ],
                    ],
                    [
                        'date' => '2026-11-04',
                        'dayName' => 'Wed',
                        'dayNumber' => '04',
                        'title' => 'Active Recovery Walk',
                        'badge' => 'RECOVERY',
                        'category' => 'walk',
                        'distance' => '3.0 km',
                        'pace' => 'Casual Walk',
                        'targetHr' => '< 115 bpm',
                        'description' => 'Gentle walking to promote venous return and flush metabolic waste. Dynamic hip flexor sweeps afterward.',
                        'exercises' => [
                            ['name' => 'Dynamic Hamstring Sweeps', 'reps' => '2 sets × 10 sweeps / leg', 'type' => 'Mobility'],
                            ['name' => 'Kneeling Hip Flexor & Quad Stretch', 'reps' => '2 sets × 45 sec / side', 'type' => 'Stretching'],
                        ],
                    ],
                    [
                        'date' => '2026-11-05',
                        'dayName' => 'Thu',
                        'dayNumber' => '05',
                        'title' => '10K Pace Lock Workout',
                        'badge' => 'PACE LOCK',
                        'category' => 'workout',
                        'distance' => '5.5 km',
                        'pace' => '4 × 1 km @ Exactly 6:12–6:18 / km',
                        'targetHr' => '152 – 156 bpm (~153 anchor)',
                        'description' => '1.0 km warm-up @ 7:00/km. Then 4 repetitions of 1,000m locked at 6:15/km (90s walk recovery between sets). Use Lap Pace! 0.5 km cool-down.',
                        'exercises' => [
                            ['name' => 'Post-Run Straight & Bent Calf Stretches', 'reps' => '3 sets × 40 sec / leg', 'type' => 'Stretching'],
                            ['name' => 'Figure-4 Glute Stretch', 'reps' => '2 sets × 45 sec / side', 'type' => 'Stretching'],
                        ],
                    ],
                    [
                        'date' => '2026-11-06',
                        'dayName' => 'Fri',
                        'dayNumber' => '06',
                        'title' => 'Rest & Home Strength Routine',
                        'badge' => 'STRENGTH',
                        'category' => 'strength',
                        'distance' => '0.0 km',
                        'pace' => 'Home Strength',
                        'targetHr' => '< 120 bpm',
                        'description' => 'Targeted runner strength session using your 2kg dumbbells. Builds hip and ankle resilience to prevent form collapse.',
                        'exercises' => [
                            ['name' => 'Goblet Squat to Calf Press (2x 2kg DBs)', 'reps' => '3 sets × 12 reps', 'type' => 'Strength'],
                            ['name' => 'Reverse Lunges with 2kg Dumbbells', 'reps' => '3 sets × 10 reps / leg', 'type' => 'Strength'],
                            ['name' => 'Single-Leg Romanian Deadlift (2kg DB)', 'reps' => '3 sets × 8–10 reps / leg', 'type' => 'Strength'],
                            ['name' => 'Side Plank Clamshells', 'reps' => '3 sets × 15 reps / side', 'type' => 'Strength'],
                            ['name' => 'Deadbug Core Stability', 'reps' => '3 sets × 45 sec', 'type' => 'Strength'],
                        ],
                    ],
                    [
                        'date' => '2026-11-07',
                        'dayName' => 'Sat',
                        'dayNumber' => '07',
                        'title' => 'Shakeout & Strides',
                        'badge' => 'SHAKEOUT',
                        'category' => 'easy',
                        'distance' => '3.0 km',
                        'pace' => '6:45 – 7:00 / km',
                        'targetHr' => '130 – 140 bpm',
                        'description' => '3.0 km very easy jog, followed by 3 × 15-second light strides (accelerating smoothly to wake up leg turnover without fatigue).',
                        'exercises' => [
                            ['name' => 'Calf Wall Eccentric Drops', 'reps' => '2 sets × 8 reps / leg (unweighted)', 'type' => 'Calf Protocol'],
                            ['name' => 'Quad & Hip Flexor Stretch', 'reps' => '2 sets × 40 sec / leg', 'type' => 'Stretching'],
                        ],
                    ],
                    [
                        'date' => '2026-11-08',
                        'dayName' => 'Sun',
                        'dayNumber' => '08',
                        'title' => 'Peak Long Run Simulation',
                        'badge' => 'LONG RUN',
                        'category' => 'long',
                        'distance' => '8.5 km',
                        'pace' => 'Km 1–3 @ 6:50 | Km 4–7 @ 6:15 | Km 8–8.5 @ 6:40',
                        'targetHr' => '140 bpm start -> 155 bpm middle',
                        'description' => 'Peak volume milestone. First 3 km gentle warm-up. Middle 4 km dialed in at 6:15/km race pace. Final 1.5 km easy cool-down. Do not exceed 8.5 km.',
                        'exercises' => [
                            ['name' => 'Full Lower Body Stretch (Calves, Soleus, Quads, Glutes)', 'reps' => '15 minutes total', 'type' => 'Stretching'],
                        ],
                    ],
                ],
            ],
            [
                'number' => 2,
                'title' => 'Week 2: Step-Down Taper Phase 1',
                'dates' => 'Nov 09 – Nov 15',
                'targetDistance' => '14.5 km Running + 2.5 km Walking',
                'focus' => 'Drop volume 25%. Maintain speed sharpness. Protect calves from micro-trauma.',
                'projectedTsb' => '+0.3 (Fatigue Breaking Away)',
                'days' => [
                    [
                        'date' => '2026-11-09',
                        'dayName' => 'Mon',
                        'dayNumber' => '09',
                        'title' => 'Complete Rest Day',
                        'badge' => 'REST',
                        'category' => 'rest',
                        'distance' => '0.0 km',
                        'pace' => 'Rest',
                        'targetHr' => '< 75 bpm',
                        'description' => 'Post-long run full recovery day. Prioritize hydration and 8+ hours sleep. No strength work.',
                        'exercises' => [
                            ['name' => 'Gentle Ankle Circles & Toe Flexions', 'reps' => '2 sets × 15 rotations', 'type' => 'Mobility'],
                        ],
                    ],
                    [
                        'date' => '2026-11-10',
                        'dayName' => 'Tue',
                        'dayNumber' => '10',
                        'title' => 'Aerobic Recovery Run',
                        'badge' => 'RECOVERY',
                        'category' => 'easy',
                        'distance' => '4.0 km',
                        'pace' => '6:55 – 7:10 / km',
                        'targetHr' => '< 140 bpm (Zone 2)',
                        'description' => 'Extremely relaxed shuffle run. Let legs spin easily. Light calf raises only.',
                        'exercises' => [
                            ['name' => 'Bent-Knee Soleus Raises (2kg DBs)', 'reps' => '2 sets × 12 reps', 'type' => 'Calf Protocol'],
                            ['name' => 'Calf Wall Stretch (Straight & Bent)', 'reps' => '2 sets × 40 sec / leg', 'type' => 'Stretching'],
                        ],
                    ],
                    [
                        'date' => '2026-11-11',
                        'dayName' => 'Wed',
                        'dayNumber' => '11',
                        'title' => 'Active Light Walk',
                        'badge' => 'WALK',
                        'category' => 'walk',
                        'distance' => '2.5 km',
                        'pace' => 'Easy Walk',
                        'targetHr' => '< 110 bpm',
                        'description' => 'Light, relaxing walk. Today is your birthday—enjoy a restful, zero-stress day!',
                        'exercises' => [
                            ['name' => 'Light Dynamic Mobility Sweeps', 'reps' => '2 sets × 10 sweeps', 'type' => 'Mobility'],
                        ],
                    ],
                    [
                        'date' => '2026-11-12',
                        'dayName' => 'Thu',
                        'dayNumber' => '12',
                        'title' => 'Threshold Rehearsal Session',
                        'badge' => 'SPEED SHARPENER',
                        'category' => 'workout',
                        'distance' => '5.5 km',
                        'pace' => '2 × 1.5 km @ 6:10 – 6:15 / km',
                        'targetHr' => '153 – 158 bpm',
                        'description' => '1.5 km warm-up @ 7:00/km. Then 2 × 1.5 km locked precisely at race pace (6:12/km) with 2 minutes walking rest. 1.0 km cool-down.',
                        'exercises' => [
                            ['name' => 'Achilles & Soleus Wall Stretches', 'reps' => '3 sets × 40 sec / leg', 'type' => 'Stretching'],
                            ['name' => 'Hamstring & Quad Stretches', 'reps' => '2 sets × 45 sec / leg', 'type' => 'Stretching'],
                        ],
                    ],
                    [
                        'date' => '2026-11-13',
                        'dayName' => 'Fri',
                        'dayNumber' => '13',
                        'title' => 'Rest & Light Mobility',
                        'badge' => 'REST',
                        'category' => 'rest',
                        'distance' => '0.0 km',
                        'pace' => 'Rest',
                        'targetHr' => '< 75 bpm',
                        'description' => 'Cease heavy resistance strength training to prevent delayed onset muscle soreness (DOMS). Light core only.',
                        'exercises' => [
                            ['name' => 'Glute Bridges (Bodyweight)', 'reps' => '2 sets × 15 reps', 'type' => 'Mobility'],
                            ['name' => 'Bird-Dog Core Stability', 'reps' => '2 sets × 10 / side', 'type' => 'Mobility'],
                        ],
                    ],
                    [
                        'date' => '2026-11-14',
                        'dayName' => 'Sat',
                        'dayNumber' => '14',
                        'title' => 'Easy Maintenance Run',
                        'badge' => 'EASY RUN',
                        'category' => 'easy',
                        'distance' => '3.0 km',
                        'pace' => '6:45 – 7:00 / km',
                        'targetHr' => '< 142 bpm',
                        'description' => 'Gentle maintenance run to keep turnover awake.',
                        'exercises' => [
                            ['name' => 'Calf & Achilles Post-Run Stretch', 'reps' => '2 sets × 40 sec', 'type' => 'Stretching'],
                        ],
                    ],
                    [
                        'date' => '2026-11-15',
                        'dayName' => 'Sun',
                        'dayNumber' => '15',
                        'title' => 'Capped Long Run',
                        'badge' => 'CAPPED LONG RUN',
                        'category' => 'long',
                        'distance' => '7.0 km',
                        'pace' => 'Km 1–2 @ 6:50 | Km 3–5 @ 6:15 | Km 6–7 @ 6:45',
                        'targetHr' => '140 – 155 bpm',
                        'description' => 'Strict 7.0 km cap. Only 3 km at race pace. Running further will impair your race-day supercompensation.',
                        'exercises' => [
                            ['name' => 'Full Post-Run Lower Body Routine', 'reps' => '10 minutes', 'type' => 'Stretching'],
                        ],
                    ],
                ],
            ],
            [
                'number' => 3,
                'title' => 'Week 3: Race Week & Supercompensation Taper',
                'dates' => 'Nov 16 – Nov 22',
                'targetDistance' => '6.7 km Shakeouts + 10.0 km Race Day',
                'focus' => 'Shed 50% remaining fatigue. Peak TSB to +7.5 on Sunday morning. Fresh legs.',
                'projectedTsb' => '+7.5 (Optimal Race Freshness)',
                'days' => [
                    [
                        'date' => '2026-11-16',
                        'dayName' => 'Mon',
                        'dayNumber' => '16',
                        'title' => 'Complete Rest Day',
                        'badge' => 'REST',
                        'category' => 'rest',
                        'distance' => '0.0 km',
                        'pace' => 'Rest',
                        'targetHr' => '< 75 bpm',
                        'description' => 'Full rest. Start increasing complex carbohydrate intake (rice, oats, potatoes). Drink 2.5L water.',
                        'exercises' => [
                            ['name' => 'Gentle Foam Rolling / Calf Massage', 'reps' => '5 minutes light', 'type' => 'Recovery'],
                        ],
                    ],
                    [
                        'date' => '2026-11-17',
                        'dayName' => 'Tue',
                        'dayNumber' => '17',
                        'title' => 'Taper Rhythm Tune-Up',
                        'badge' => 'TUNE-UP',
                        'category' => 'workout',
                        'distance' => '3.5 km',
                        'pace' => '1.5km easy + 1.0km @ 6:15 + 1.0km easy',
                        'targetHr' => '< 154 bpm',
                        'description' => 'Just 1 km at race pace to remind your nervous system of the 6:15 cadence. Finish feeling completely energized.',
                        'exercises' => [
                            ['name' => 'Light Post-Run Calf Wall Stretches', 'reps' => '2 sets × 30 sec / leg', 'type' => 'Stretching'],
                        ],
                    ],
                    [
                        'date' => '2026-11-18',
                        'dayName' => 'Wed',
                        'dayNumber' => '18',
                        'title' => 'Rest Day & Light Stroll',
                        'badge' => 'REST',
                        'category' => 'rest',
                        'distance' => '0.0 km',
                        'pace' => 'Rest',
                        'targetHr' => '< 75 bpm',
                        'description' => 'Zero running. Optional 15-minute relaxed stroll. Keep feet elevated when resting.',
                        'exercises' => [
                            ['name' => 'Legs Up the Wall (Elevation)', 'reps' => '10 minutes hold', 'type' => 'Recovery'],
                        ],
                    ],
                    [
                        'date' => '2026-11-19',
                        'dayName' => 'Thu',
                        'dayNumber' => '19',
                        'title' => 'Shakeout & Strides',
                        'badge' => 'SHAKEOUT',
                        'category' => 'easy',
                        'distance' => '2.5 km',
                        'pace' => '6:50 / km + 3 Strides',
                        'targetHr' => '< 140 bpm',
                        'description' => '2.5 km very slow jog followed by 3 × 50m gentle accelerations. Practice breathing rhythm.',
                        'exercises' => [
                            ['name' => 'Dynamic Calf Sweeps', 'reps' => '10 sweeps / leg', 'type' => 'Mobility'],
                        ],
                    ],
                    [
                        'date' => '2026-11-20',
                        'dayName' => 'Fri',
                        'dayNumber' => '20',
                        'title' => 'Complete Rest Day',
                        'badge' => 'REST',
                        'category' => 'rest',
                        'distance' => '0.0 km',
                        'pace' => 'Rest',
                        'targetHr' => '< 75 bpm',
                        'description' => 'Total rest. High carb meals. Avoid long standing or walking. Calves should feel totally relaxed.',
                        'exercises' => [
                            ['name' => 'Mental Pacing Visualization (6:15 splits)', 'reps' => '10 minutes', 'type' => 'Mental'],
                        ],
                    ],
                    [
                        'date' => '2026-11-21',
                        'dayName' => 'Sat',
                        'dayNumber' => '21',
                        'title' => 'Pre-Race Primer',
                        'badge' => 'PRIMER',
                        'category' => 'easy',
                        'distance' => '1.2 km',
                        'pace' => '7:00 / km (10 mins)',
                        'targetHr' => '< 130 bpm',
                        'description' => 'Short 10-minute jog wearing your race shoes and race kit. Check bib, pins, watch battery, and breakfast.',
                        'exercises' => [
                            ['name' => 'Light Ankle Circles & Calf Stretch', 'reps' => '5 minutes', 'type' => 'Mobility'],
                        ],
                    ],
                    [
                        'date' => '2026-11-22',
                        'dayName' => 'Sun',
                        'dayNumber' => '22',
                        'title' => '🏁 10K RACE DAY',
                        'badge' => 'RACE DAY',
                        'category' => 'race',
                        'distance' => '10.0 km',
                        'pace' => 'Goal: 6:15 / km (Finish: 1:02:30)',
                        'targetHr' => '150–155 bpm mid-race -> max finish',
                        'description' => 'Race Strategy: Km 0–2 @ 6:20–6:25 (don’t surge with crowd!). Km 2–7 lock 6:12–6:18 (HR ~153). Km 7–9 hold steady cadence as calves tire. Km 9–10 empty the tank!',
                        'exercises' => [
                            ['name' => 'Pre-Race Dynamic Warm-Up (No static stretching)', 'reps' => '8 minutes', 'type' => 'Warm-Up'],
                            ['name' => 'Celebrate Your Race & Post-Race Walk', 'reps' => '15 minutes', 'type' => 'Recovery'],
                        ],
                    ],
                ],
            ],
        ];

        return [
            'goalTitle' => '10K Race Preparation — 22nd November 2026',
            'targetPace' => '6:15 min/km',
            'targetTime' => '1:02:30',
            'currentCtl' => '30.4',
            'currentAtl' => '44.7',
            'currentTsb' => '-14.3',
            'targetRaceTsb' => '+7.5',
            'acwr' => '1.47',
            'weeks' => $weeks,
        ];
    }
}
