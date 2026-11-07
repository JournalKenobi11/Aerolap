<?php

declare(strict_types=1);

namespace App\Controller\Page;

use App\Application\Navigation\NavigationSection;
use App\Application\PageRenderer;
use App\Domain\Activity\Running\TrainingStress\AthleteSubjectiveLogRepository;
use App\Domain\Activity\Running\TrainingStress\TrainingStressService;
use App\Infrastructure\Cache\Cacheability;
use App\Infrastructure\Cache\Render\RenderCache;
use App\Infrastructure\Cache\Tag\CacheTags;
use App\Infrastructure\Cache\Tag\RootCacheTag;
use App\Infrastructure\Http\HtmlResponse;
use App\Infrastructure\Time\Clock\Clock;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[AsController]
final readonly class TrainingStressRequestHandler
{
    public function __construct(
        private Environment $twig,
        private PageRenderer $pageRenderer,
        private TrainingStressService $trainingStressService,
        private AthleteSubjectiveLogRepository $subjectiveLogRepository,
        private RenderCache $renderCache,
        private Clock $clock,
    ) {
    }

    #[Route(path: '/training-stress', name: 'training_stress', methods: ['GET'])]
    public function handle(): HtmlResponse
    {
        $todayStr = $this->clock->getCurrentDateTimeImmutable()->format('Y-m-d');
        $latestSubjective = $this->subjectiveLogRepository->findLatest();
        $subKey = $latestSubjective ? ($latestSubjective['date'] . '-' . $latestSubjective['sorenessScore']) : 'none';

        return $this->pageRenderer->render(
            cacheability: Cacheability::for(
                cacheKey: 'training-stress-' . $todayStr . '-' . $subKey,
                cacheTags: CacheTags::of(RootCacheTag::ACTIVITIES),
            ),
            render: fn (): string => $this->renderView(),
            navigationSection: NavigationSection::TRAINING_STRESS,
        );
    }

    #[Route(path: '/training-stress/log-soreness', name: 'training_stress_log_soreness', methods: ['POST'])]
    public function logSoreness(Request $request): Response
    {
        $score = (int) $request->request->get('sorenessScore', 0);
        $location = trim((string) $request->request->get('location', 'None'));
        $notes = trim((string) $request->request->get('notes', ''));
        $date = trim((string) $request->request->get('date', ''));

        if ($date === '') {
            $date = $this->clock->getCurrentDateTimeImmutable()->format('Y-m-d');
        }

        $this->subjectiveLogRepository->record(
            date: $date,
            sorenessScore: $score,
            location: $location !== '' ? $location : 'None',
            notes: $notes !== '' ? $notes : null,
        );

        // Invalidate activities cache tag to force render refresh
        $this->renderCache->invalidateTags(RootCacheTag::ACTIVITIES);

        return new RedirectResponse('/training-stress');
    }

    private function renderView(): string
    {
        $result = $this->trainingStressService->analyze();
        $cardioChart = \App\Domain\Activity\Running\TrainingStress\TrainingStressChartBuilder::buildCardioChart($result->chartData);
        $volumeChart = \App\Domain\Activity\Running\TrainingStress\TrainingStressChartBuilder::buildVolumeChart($result->chartData);

        return $this->twig->load('html/training-stress.html.twig')->render([
            'stress' => $result,
            'cardioChart' => $cardioChart,
            'volumeChart' => $volumeChart,
        ]);
    }
}
