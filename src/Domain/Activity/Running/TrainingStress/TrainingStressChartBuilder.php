<?php

declare(strict_types=1);

namespace App\Domain\Activity\Running\TrainingStress;

use App\Infrastructure\Serialization\Json;

final readonly class TrainingStressChartBuilder
{
    /**
     * @param array<string, mixed> $chartData
     */
    public static function buildDualPathwayChart(array $chartData): string
    {
        $dates = array_map(function (string $d): string {
            return (new \DateTimeImmutable($d))->format('M d');
        }, $chartData['dualPathwayDays'] ?? $chartData['cardioDays'] ?? []);

        $options = [
            'tooltip' => [
                'trigger' => 'axis',
                'axisPointer' => ['type' => 'cross'],
            ],
            'legend' => [
                'data' => [
                    'Cardiovascular Strain (ICS - Heart)',
                    'Structural Tissue Load (STL - Tendon/Bone)',
                ],
                'top' => '0px',
                'textStyle' => ['color' => '#64748b', 'fontSize' => 11],
            ],
            'grid' => [
                'left' => '50px',
                'right' => '50px',
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
                [
                    'type' => 'value',
                    'name' => 'Cardio (ICS)',
                    'nameTextStyle' => ['color' => '#0284c7', 'fontSize' => 10],
                    'splitLine' => ['lineStyle' => ['color' => '#f1f5f9']],
                    'axisLabel' => ['color' => '#0284c7', 'fontSize' => 10],
                ],
                [
                    'type' => 'value',
                    'name' => 'Tissue (STL)',
                    'nameTextStyle' => ['color' => '#9333ea', 'fontSize' => 10],
                    'splitLine' => ['show' => false],
                    'axisLabel' => ['color' => '#9333ea', 'fontSize' => 10],
                ],
            ],
            'series' => [
                [
                    'name' => 'Cardiovascular Strain (ICS - Heart)',
                    'type' => 'line',
                    'yAxisIndex' => 0,
                    'smooth' => true,
                    'data' => $chartData['icsSeries'] ?? $chartData['ctlSeries'] ?? [],
                    'itemStyle' => ['color' => '#0284c7'],
                    'lineStyle' => ['width' => 2.5],
                    'areaStyle' => [
                        'color' => [
                            'type' => 'linear',
                            'x' => 0, 'y' => 0, 'x2' => 0, 'y2' => 1,
                            'colorStops' => [
                                ['offset' => 0, 'color' => 'rgba(2, 132, 199, 0.22)'],
                                ['offset' => 1, 'color' => 'rgba(2, 132, 199, 0.0)'],
                            ],
                        ],
                    ],
                    'showSymbol' => false,
                ],
                [
                    'name' => 'Structural Tissue Load (STL - Tendon/Bone)',
                    'type' => 'line',
                    'yAxisIndex' => 1,
                    'smooth' => true,
                    'data' => $chartData['stlSeries'] ?? $chartData['atlSeries'] ?? [],
                    'itemStyle' => ['color' => '#9333ea'],
                    'lineStyle' => ['width' => 2.5],
                    'areaStyle' => [
                        'color' => [
                            'type' => 'linear',
                            'x' => 0, 'y' => 0, 'x2' => 0, 'y2' => 1,
                            'colorStops' => [
                                ['offset' => 0, 'color' => 'rgba(147, 51, 234, 0.18)'],
                                ['offset' => 1, 'color' => 'rgba(147, 51, 234, 0.0)'],
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
     * Backward-compatible alias for buildDualPathwayChart
     * @param array<string, mixed> $chartData
     */
    public static function buildCardioChart(array $chartData): string
    {
        return self::buildDualPathwayChart($chartData);
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
                'data' => ['Run Distance (km)', 'Walk/Hike Equivalent (km)', '21d Chronic Base (km)'],
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
                    'itemStyle' => ['color' => '#0284c7'],
                    'barMaxWidth' => 28,
                ],
                [
                    'name' => 'Walk/Hike Equivalent (km)',
                    'type' => 'bar',
                    'stack' => 'total',
                    'data' => $chartData['weeklyWalkData'] ?? [],
                    'itemStyle' => ['color' => '#94a3b8'],
                    'barMaxWidth' => 28,
                ],
                [
                    'name' => '21d Chronic Base (km)',
                    'type' => 'line',
                    'data' => $chartData['weeklyBaseData'] ?? [],
                    'itemStyle' => ['color' => '#f59e0b'],
                    'lineStyle' => ['width' => 2, 'type' => 'dashed'],
                    'showSymbol' => true,
                    'symbolSize' => 6,
                ],
            ],
        ];

        return Json::encode($options);
    }
}
