<?php

declare(strict_types=1);

namespace App\Domain\Activity\Running\TrainingStress;

use App\Infrastructure\Serialization\Json;

final readonly class TrainingStressChartBuilder
{
    /**
     * @param array<string, mixed> $chartData
     */
    public static function buildCardioChart(array $chartData): string
    {
        $dates = array_map(function (string $d): string {
            return (new \DateTimeImmutable($d))->format('M d');
        }, $chartData['cardioDays'] ?? []);

        $options = [
            'tooltip' => [
                'trigger' => 'axis',
                'axisPointer' => ['type' => 'cross'],
            ],
            'legend' => [
                'data' => ['Cardio Base (CTL)', 'Acute Fatigue (ATL)', 'Freshness (TSB)'],
                'top' => '0px',
                'textStyle' => ['color' => '#64748b', 'fontSize' => 11],
            ],
            'grid' => [
                'left' => '45px',
                'right' => '20px',
                'top' => '35px',
                'bottom' => '25px',
            ],
            'xAxis' => [
                'type' => 'category',
                'data' => $dates,
                'axisLine' => ['lineStyle' => ['color' => '#cbd5e1']],
                'axisLabel' => ['color' => '#64748b', 'fontSize' => 10],
            ],
            'yAxis' => [
                'type' => 'value',
                'splitLine' => ['lineStyle' => ['color' => '#f1f5f9']],
                'axisLabel' => ['color' => '#64748b', 'fontSize' => 10],
            ],
            'series' => [
                [
                    'name' => 'Cardio Base (CTL)',
                    'type' => 'line',
                    'smooth' => true,
                    'data' => $chartData['ctlSeries'] ?? [],
                    'itemStyle' => ['color' => '#2DB6FA'],
                    'lineStyle' => ['width' => 2.5],
                    'showSymbol' => false,
                ],
                [
                    'name' => 'Acute Fatigue (ATL)',
                    'type' => 'line',
                    'smooth' => true,
                    'data' => $chartData['atlSeries'] ?? [],
                    'itemStyle' => ['color' => '#A855F7'],
                    'lineStyle' => ['width' => 2],
                    'showSymbol' => false,
                ],
                [
                    'name' => 'Freshness (TSB)',
                    'type' => 'line',
                    'smooth' => true,
                    'data' => $chartData['tsbSeries'] ?? [],
                    'itemStyle' => ['color' => '#10B981'],
                    'lineStyle' => ['width' => 1.5, 'type' => 'dashed'],
                    'areaStyle' => [
                        'color' => [
                            'type' => 'linear',
                            'x' => 0, 'y' => 0, 'x2' => 0, 'y2' => 1,
                            'colorStops' => [
                                ['offset' => 0, 'color' => 'rgba(16, 185, 129, 0.25)'],
                                ['offset' => 1, 'color' => 'rgba(16, 185, 129, 0.0)'],
                            ],
                        ],
                    ],
                    'showSymbol' => false,
                ],
            ],
        ];

        return Json::encode($options);
    }

    /**
     * @param array<string, mixed> $chartData
     */
    public static function buildVolumeChart(array $chartData): string
    {
        $options = [
            'tooltip' => [
                'trigger' => 'axis',
                'axisPointer' => ['type' => 'shadow'],
            ],
            'legend' => [
                'data' => ['Run Distance (km)', 'Walk/Hike Effective Load (km)', '21d Chronic Base (km)'],
                'top' => '0px',
                'textStyle' => ['color' => '#64748b', 'fontSize' => 11],
            ],
            'grid' => [
                'left' => '45px',
                'right' => '20px',
                'top' => '35px',
                'bottom' => '25px',
            ],
            'xAxis' => [
                'type' => 'category',
                'data' => $chartData['weeklyCategories'] ?? [],
                'axisLine' => ['lineStyle' => ['color' => '#cbd5e1']],
                'axisLabel' => ['color' => '#64748b', 'fontSize' => 10],
            ],
            'yAxis' => [
                'type' => 'value',
                'splitLine' => ['lineStyle' => ['color' => '#f1f5f9']],
                'axisLabel' => ['color' => '#64748b', 'fontSize' => 10],
            ],
            'series' => [
                [
                    'name' => 'Run Distance (km)',
                    'type' => 'bar',
                    'stack' => 'total',
                    'data' => $chartData['weeklyRunData'] ?? [],
                    'itemStyle' => ['color' => '#2DB6FA'],
                    'barMaxWidth' => 28,
                ],
                [
                    'name' => 'Walk/Hike Effective Load (km)',
                    'type' => 'bar',
                    'stack' => 'total',
                    'data' => $chartData['weeklyWalkData'] ?? [],
                    'itemStyle' => ['color' => '#94A3B8'],
                    'barMaxWidth' => 28,
                ],
                [
                    'name' => '21d Chronic Base (km)',
                    'type' => 'line',
                    'data' => $chartData['weeklyBaseData'] ?? [],
                    'itemStyle' => ['color' => '#F59E0B'],
                    'lineStyle' => ['width' => 2, 'type' => 'dashed'],
                    'showSymbol' => true,
                    'symbolSize' => 6,
                ],
            ],
        ];

        return Json::encode($options);
    }
}
