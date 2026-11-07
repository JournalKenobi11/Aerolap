<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget;

use App\Domain\Activity\Running\TrainingStress\TrainingStressService;
use App\Domain\Dashboard\DashboardWidgetId;
use App\Infrastructure\Cache\Tag\CacheTags;
use App\Infrastructure\Cache\Tag\RootCacheTag;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class TrainingStressWidget implements Widget
{
    public function __construct(
        private TranslatorInterface $translator,
        private TrainingStressService $trainingStressService,
        private WidgetRenderer $widgetRenderer,
    ) {
    }

    public function getLabel(): string
    {
        return $this->translator->trans('Workload & Stress');
    }

    public function getAdminLabel(): string
    {
        return $this->getLabel();
    }

    public function getTemplateName(): string
    {
        return 'widget--training-stress';
    }

    public function getCacheTags(): CacheTags
    {
        return CacheTags::of(RootCacheTag::ACTIVITIES);
    }

    public function getDefaultConfiguration(): WidgetConfiguration
    {
        return WidgetConfiguration::empty();
    }

    public function guardValidConfiguration(WidgetConfiguration $configuration): void
    {
    }

    public function render(DashboardWidgetId $dashboardWidgetId, SerializableDateTime $now, WidgetConfiguration $configuration): ?string
    {
        $stress = $this->trainingStressService->analyze();

        return $this->widgetRenderer->render($this, $configuration, [
            'stress' => $stress,
        ]);
    }
}
