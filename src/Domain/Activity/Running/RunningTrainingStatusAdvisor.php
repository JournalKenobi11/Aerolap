<?php

declare(strict_types=1);

namespace App\Domain\Activity\Running;

final readonly class RunningTrainingStatusAdvisor
{
    public function __construct(
        private string $status,
        private string $headline,
        private string $plainLanguageExplanation,
        private string $nextWorkoutGuidance,
        private string $badgeColor,
        private float $ctl,
        private float $atl,
        private float $tsb,
        private float $acRatio,
        private ?float $monotony = null,
        private ?float $strain = null,
    ) {
    }

    public static function analyze(
        float $ctl,
        float $atl,
        float $tsb,
        float $acRatio,
        ?float $monotony = null,
        ?float $strain = null,
        float $ctlChangeLast7Days = 0.0,
    ): self {
        // Evaluate training state based on sports science models (Bannister, Coggan, Gabbett ACWR)
        if ($tsb > 12.0 && $ctl >= 25.0 && $ctlChangeLast7Days >= -2.0) {
            $status = 'Peaking';
            $headline = 'Form is High – Ready for Peak Performance';
            $explanation = sprintf('Your training fatigue (ATL %.1f) has cleared while retaining high chronic fitness (CTL %.1f). Your Form (TSB +%.1f) is in the optimal race readiness zone.', $atl, $ctl, $tsb);
            $guidance = 'Ideal window for a race, time trial, or breakthrough personal best attempt.';
            $badgeColor = 'text-emerald-700 bg-emerald-50 border-emerald-300';
        } elseif ($acRatio > 1.45 || $tsb < -28.0) {
            $status = 'Overreaching';
            $headline = 'High Fatigue & Elevated Injury Risk';
            $explanation = sprintf('Acute workload (ATL %.1f) has surged faster than your historical base (CTL %.1f). Acute-to-Chronic Ratio (%.2f) exceeds safe progression thresholds.', $atl, $ctl, $acRatio);
            $guidance = 'Take a rest day or keep today\'s run strictly in Zone 1 (Recovery) under 30 minutes to absorb training and prevent injury.';
            $badgeColor = 'text-red-700 bg-red-50 border-red-300';
        } elseif ($ctlChangeLast7Days >= 0.5 && $acRatio >= 0.85 && $acRatio <= 1.35) {
            $status = 'Productive';
            $headline = 'Optimal Fitness Progression';
            $explanation = sprintf('Your fitness (CTL %.1f) is steadily increasing (+%.1f over 7 days). Acute-to-Chronic Ratio (%.2f) is in the sweet spot (0.8 - 1.3) where aerobic adaptations happen safely.', $ctl, $ctlChangeLast7Days, $acRatio);
            $guidance = 'Continue your scheduled plan. Today is well-suited for a progressive tempo run or planned interval workout.';
            $badgeColor = 'text-green-700 bg-green-50 border-green-300';
        } elseif ($tsb > 20.0 && $ctlChangeLast7Days < -1.5) {
            $status = 'Detraining';
            $headline = 'Fitness Declining Due to Low Volume';
            $explanation = sprintf('Chronic training load (CTL %.1f) has dropped by %.1f points. While fatigue is minimal, aerobic base adaptations are fading.', $ctl, abs($ctlChangeLast7Days));
            $guidance = 'Gradually rebuild weekly running consistency with comfortable Zone 2 aerobic base runs.';
            $badgeColor = 'text-amber-700 bg-amber-50 border-amber-300';
        } elseif ($tsb >= 5.0 && $atl < $ctl) {
            $status = 'Recovery';
            $headline = 'Recovering & Absorbing Workload';
            $explanation = sprintf('Fatigue (ATL %.1f) is dropping faster than fitness (CTL %.1f). Your body is adapting to recent hard training blocks.', $atl, $ctl);
            $guidance = 'Keep effort easy to moderate. Maintain aerobic circulation without introducing heavy leg fatigue.';
            $badgeColor = 'text-blue-700 bg-blue-50 border-blue-300';
        } else {
            $status = 'Maintaining';
            $headline = 'Stable Fitness & Balanced Workload';
            $explanation = sprintf('Your training load is consistent (CTL %.1f, Form TSB %s%.1f). Fitness is stable without acute fatigue buildup.', $ctl, $tsb >= 0 ? '+' : '', $tsb);
            $guidance = 'Great foundation. Well-suited for standard aerobic training or introducing a quality workout if feeling energized.';
            $badgeColor = 'text-indigo-700 bg-indigo-50 border-indigo-300';
        }

        if (null !== $monotony && $monotony > 2.0) {
            $explanation .= sprintf(' Notice: Training monotony (%.2f) is elevated, indicating repetitive daily training loads. Add varied intensity or scheduled rest days.', $monotony);
        }

        return new self(
            status: $status,
            headline: $headline,
            plainLanguageExplanation: $explanation,
            nextWorkoutGuidance: $guidance,
            badgeColor: $badgeColor,
            ctl: $ctl,
            atl: $atl,
            tsb: $tsb,
            acRatio: $acRatio,
            monotony: $monotony,
            strain: $strain,
        );
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getHeadline(): string
    {
        return $this->headline;
    }

    public function getPlainLanguageExplanation(): string
    {
        return $this->plainLanguageExplanation;
    }

    public function getNextWorkoutGuidance(): string
    {
        return $this->nextWorkoutGuidance;
    }

    public function getBadgeColor(): string
    {
        return $this->badgeColor;
    }

    public function getCtl(): float
    {
        return $this->ctl;
    }

    public function getAtl(): float
    {
        return $this->atl;
    }

    public function getTsb(): float
    {
        return $this->tsb;
    }

    public function getAcRatio(): float
    {
        return $this->acRatio;
    }

    public function getMonotony(): ?float
    {
        return $this->monotony;
    }

    public function getStrain(): ?float
    {
        return $this->strain;
    }
}
