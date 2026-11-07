<?php

declare(strict_types=1);

namespace App\Domain\Activity\Running\TrainingStress;

final readonly class TrainingStressResult
{
    /**
     * @param array{label: string, code: string, color: string, description: string} $statusBadge
     * @param array<string, mixed>|null $subjectiveLog
     * @param list<array<string, mixed>> $historyWeeks
     * @param array<string, mixed> $chartData
     */
    public function __construct(
        public string $configVersion,
        public array $statusBadge,
        public bool $isInsufficientHistory,
        public bool $isTapering,
        public bool $isPostRace,
        public bool $hasSorenessOverride,
        public bool $hasSorenessCaution,
        public bool $hasConsecutiveSorenessAlert,
        public ?array $subjectiveLog,
        public float $acuteRunKm,
        public float $acuteWalkKm,
        public float $acuteHikeKm,
        public float $acuteEffectiveKm,
        public int $acuteRunCount,
        public float $chronicBaseRunKm,
        public float $chronicBaseEffectiveKm,
        public float $volumeStepKm,
        public float $allowedStepKm,
        public string $volumeStepStatus,
        public float $longestRunKm,
        public float $prior30dMaxLongKm,
        public float $longRunStepKm,
        public float $allowedLongRunStepKm,
        public string $longRunStatus,
        public float $ctl,
        public float $atl,
        public float $tsb,
        public float $tsbPercentile,
        public bool $isTsbLow,
        public int $athleteMaxHr,
        public int $athleteRestingHr,
        public string $hrCalcMethod,
        public ?string $targetRaceDate,
        public ?int $daysToRace,
        public string $guidanceHeadline,
        public string $guidanceText,
        public array $historyWeeks,
        public array $chartData,
        public float $acuteIcs = 0.0,
        public float $chronicBaseIcs = 0.0,
        public float $cardioAcwr = 1.0,
        public string $cardioStatus = 'Balanced',
        public float $acuteStl = 0.0,
        public float $chronicBaseStl = 0.0,
        public float $tissueAcwr = 1.0,
        public string $tissueStatus = 'Steady',
        public float $divergence = 0.0,
        public string $divergenceStatus = 'Equilibrium',
        public string $divergenceLabel = 'Balanced Adaptation',
        public string $prescriptionTitle = "Today's Guidance",
        public string $prescriptionText = '',
        public float $athleteWeightKg = 58.4,
        public int $targetDistanceMeters = 10000,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'configVersion' => $this->configVersion,
            'statusBadge' => $this->statusBadge,
            'isInsufficientHistory' => $this->isInsufficientHistory,
            'isTapering' => $this->isTapering,
            'isPostRace' => $this->isPostRace,
            'hasSorenessOverride' => $this->hasSorenessOverride,
            'hasSorenessCaution' => $this->hasSorenessCaution,
            'hasConsecutiveSorenessAlert' => $this->hasConsecutiveSorenessAlert,
            'subjectiveLog' => $this->subjectiveLog,
            'acuteRunKm' => $this->acuteRunKm,
            'acuteWalkKm' => $this->acuteWalkKm,
            'acuteHikeKm' => $this->acuteHikeKm,
            'acuteEffectiveKm' => $this->acuteEffectiveKm,
            'acuteRunCount' => $this->acuteRunCount,
            'chronicBaseRunKm' => $this->chronicBaseRunKm,
            'chronicBaseEffectiveKm' => $this->chronicBaseEffectiveKm,
            'volumeStepKm' => $this->volumeStepKm,
            'allowedStepKm' => $this->allowedStepKm,
            'volumeStepStatus' => $this->volumeStepStatus,
            'longestRunKm' => $this->longestRunKm,
            'prior30dMaxLongKm' => $this->prior30dMaxLongKm,
            'longRunStepKm' => $this->longRunStepKm,
            'allowedLongRunStepKm' => $this->allowedLongRunStepKm,
            'longRunStatus' => $this->longRunStatus,
            'ctl' => $this->ctl,
            'atl' => $this->atl,
            'tsb' => $this->tsb,
            'tsbPercentile' => $this->tsbPercentile,
            'isTsbLow' => $this->isTsbLow,
            'athleteMaxHr' => $this->athleteMaxHr,
            'athleteRestingHr' => $this->athleteRestingHr,
            'hrCalcMethod' => $this->hrCalcMethod,
            'targetRaceDate' => $this->targetRaceDate,
            'daysToRace' => $this->daysToRace,
            'guidanceHeadline' => $this->guidanceHeadline,
            'guidanceText' => $this->guidanceText,
            'historyWeeks' => $this->historyWeeks,
            'chartData' => $this->chartData,
            'acuteIcs' => $this->acuteIcs,
            'chronicBaseIcs' => $this->chronicBaseIcs,
            'cardioAcwr' => $this->cardioAcwr,
            'cardioStatus' => $this->cardioStatus,
            'acuteStl' => $this->acuteStl,
            'chronicBaseStl' => $this->chronicBaseStl,
            'tissueAcwr' => $this->tissueAcwr,
            'tissueStatus' => $this->tissueStatus,
            'divergence' => $this->divergence,
            'divergenceStatus' => $this->divergenceStatus,
            'divergenceLabel' => $this->divergenceLabel,
            'prescriptionTitle' => $this->prescriptionTitle,
            'prescriptionText' => $this->prescriptionText,
            'athleteWeightKg' => $this->athleteWeightKg,
            'targetDistanceMeters' => $this->targetDistanceMeters,
        ];
    }
}
