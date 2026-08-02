<?php

namespace App\Filament\Widgets;

use App\Filament\Support\AnalyticsRange;
use App\Filament\Widgets\Concerns\HandlesDashboardWidgetExceptions;
use App\Models\Profile;
use App\Models\ProSubscription;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Database\Eloquent\Builder;

class ProfilesProTrendChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use HandlesDashboardWidgetExceptions;

    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = [
        'xl' => 6,
    ];

    protected ?string $heading = 'Нові профілі та PRO-підключення';

    protected ?string $maxHeight = '220px';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        try {
            $range = AnalyticsRange::fromFilters($this->pageFilters ?? []);

            $labels = [];
            for ($i = 0; $i < $range['days']; $i++) {
                $labels[] = $range['start']->addDays($i)->format('d.m');
            }

            $profilesSeries = $this->series(Profile::query(), $range);
            $proSeries = $this->series(ProSubscription::query()->where('status', 'active'), $range);

            return [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => sprintf('Профілі (%s)', number_format(array_sum($profilesSeries), 0, '.', ' ')),
                        'data' => $profilesSeries,
                        'borderColor' => '#6366F1',
                        'backgroundColor' => 'rgba(99,102,241,.10)',
                        'fill' => true,
                        'tension' => 0.35,
                    ],
                    [
                        'label' => sprintf('PRO підключення (%s)', number_format(array_sum($proSeries), 0, '.', ' ')),
                        'data' => $proSeries,
                        'borderColor' => '#F59E0B',
                        'backgroundColor' => 'rgba(245,158,11,.10)',
                        'fill' => true,
                        'tension' => 0.35,
                    ],
                ],
            ];
        } catch (\Throwable $exception) {
            $this->reportDashboardWidgetException('getData', $exception);

            return [
                'labels' => ['—'],
                'datasets' => [
                    [
                        'label' => 'Дані тимчасово недоступні',
                        'data' => [0],
                        'borderColor' => '#94A3B8',
                        'backgroundColor' => 'rgba(148,163,184,.08)',
                        'fill' => true,
                        'tension' => 0.35,
                    ],
                ],
            ];
        }
    }

    protected function getOptions(): array | RawJs | null
    {
        return [
            'plugins' => [
                'legend' => ['display' => true],
            ],
            'scales' => [
                'y' => ['beginAtZero' => true],
            ],
        ];
    }

    /**
     * @param  array{start:\Carbon\CarbonImmutable,end:\Carbon\CarbonImmutable,days:int}  $range
     * @return array<int, int>
     */
    protected function series(Builder $query, array $range): array
    {
        $rows = $query
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->whereBetween('created_at', [$range['start'], $range['end']])
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get()
            ->pluck('total', 'day');

        $values = [];
        for ($i = 0; $i < $range['days']; $i++) {
            $day = $range['start']->addDays($i)->toDateString();
            $values[] = (int) ($rows[$day] ?? 0);
        }

        return $values;
    }
}
