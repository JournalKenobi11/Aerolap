<?php

declare(strict_types=1);

namespace App\Controller\Page;

use App\Application\Navigation\NavigationSection;
use App\Application\PageRenderer;
use App\Domain\Activity\Running\RunPlanService;
use App\Infrastructure\Cache\Cacheability;
use App\Infrastructure\Cache\Tag\CacheTags;
use App\Infrastructure\Cache\Tag\RootCacheTag;
use App\Infrastructure\Http\HtmlResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[AsController]
final readonly class RunPlanRequestHandler
{
    private string $storageDir;
    private string $customPlanFile;

    public function __construct(
        private Environment $twig,
        private PageRenderer $pageRenderer,
        private RunPlanService $runPlanService,
    ) {
        $this->storageDir = dirname(__DIR__, 3).'/storage/database';
        $this->customPlanFile = $this->storageDir.'/runwise_custom_plan.json';
    }

    #[Route(path: '/run-plan', name: 'run_plan', methods: ['GET'])]
    public function handle(): HtmlResponse
    {
        return $this->pageRenderer->render(
            cacheability: Cacheability::for(
                cacheKey: 'run-plan-view-'.(file_exists($this->customPlanFile) ? (string) filemtime($this->customPlanFile) : 'default'),
                cacheTags: CacheTags::of(RootCacheTag::ACTIVITIES),
            ),
            render: fn (): string => $this->renderView(),
            navigationSection: NavigationSection::RUN_PLAN,
        );
    }

    #[Route(path: '/run-plan/save-gmail', name: 'run_plan_save_gmail', methods: ['POST'])]
    public function saveGmail(Request $request): Response
    {
        $gmail = trim((string) $request->request->get('gmail', ''));
        if ($gmail !== '') {
            $this->runPlanService->saveGmail($gmail);
        }

        return new RedirectResponse('/run-plan');
    }

    #[Route(path: '/run-plan/import-ics', name: 'run_plan_import_ics', methods: ['POST'])]
    public function importIcs(Request $request): Response
    {
        $icsContent = '';
        $uploadedFile = $request->files->get('ics_file');
        if ($uploadedFile && $uploadedFile->isValid()) {
            $icsContent = (string) file_get_contents($uploadedFile->getPathname());
        } elseif ($request->request->has('ics_content')) {
            $icsContent = (string) $request->request->get('ics_content', '');
        }

        if (trim($icsContent) !== '') {
            $importedPlan = $this->runPlanService->parseIcsContent($icsContent);
            if (!empty($importedPlan['weeks'])) {
                $this->runPlanService->saveCustomPlan($importedPlan);
            }
        }

        return new RedirectResponse('/run-plan');
    }

    #[Route(path: '/run-plan/reset', name: 'run_plan_reset', methods: ['POST'])]
    public function resetPlan(): Response
    {
        $this->runPlanService->resetPlan();

        return new RedirectResponse('/run-plan');
    }

    #[Route(path: '/run-plan/calendar.ics', name: 'run_plan_ics', methods: ['GET'])]
    public function downloadIcs(): Response
    {
        $plan = $this->runPlanService->getActivePlanData();
        $icsLines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Runwise Running Coach//10K Race Plan 615//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:Runwise 10K Race Plan (6:15 Pace)',
            'X-WR-TIMEZONE:Asia/Kolkata',
        ];

        foreach ($plan['weeks'] as $week) {
            foreach ($week['days'] as $day) {
                $dtStart = str_replace('-', '', $day['date']);
                $nextDay = (new \DateTimeImmutable($day['date']))->modify('+1 day')->format('Ymd');
                $summary = ($day['badge'] ?? 'WORKOUT').' - '.$day['title'].' ('.($day['distance'] ?? '0.0 km').')';
                $description = "Pace: {$day['pace']}\\nTarget HR: {$day['targetHr']}\\n\\nDetails:\\n{$day['description']}\\n\\nRecommended Exercises:\\n";
                if (!empty($day['exercises'])) {
                    foreach ($day['exercises'] as $ex) {
                        $description .= "- {$ex['name']} ({$ex['reps']})\\n";
                    }
                }

                $icsLines[] = 'BEGIN:VEVENT';
                $icsLines[] = 'UID:runwise-plan-'.$dtStart.'@runwise.local';
                $icsLines[] = 'DTSTAMP:'.gmdate('Ymd\THis\Z');
                $icsLines[] = 'DTSTART;VALUE=DATE:'.$dtStart;
                $icsLines[] = 'DTEND;VALUE=DATE:'.$nextDay;
                $icsLines[] = 'SUMMARY:'.$summary;
                $icsLines[] = 'DESCRIPTION:'.$description;
                $icsLines[] = 'STATUS:CONFIRMED';
                $icsLines[] = 'END:VEVENT';
            }
        }

        $icsLines[] = 'END:VCALENDAR';
        $icsContent = implode("\r\n", $icsLines);

        return new Response($icsContent, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="Runwise_10K_Race_Plan_615.ics"',
        ]);
    }

    private function renderView(): string
    {
        $plan = $this->runPlanService->getActivePlanData();
        $config = $this->runPlanService->getConfig();
        $plan['userGmail'] = $config['gmail'] ?? 'aashaykdgr@gmail.com';
        $plan['hasCustomGmail'] = isset($config['gmail']) && $config['gmail'] !== '';
        $plan['isCustomPlan'] = file_exists($this->customPlanFile);

        return $this->twig->load('html/run-plan.html.twig')->render($plan);
    }
}
