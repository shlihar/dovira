<?php

namespace App\Filament\Widgets;

use App\Filament\Support\AnalyticsRange;
use App\Filament\Widgets\Concerns\HandlesDashboardWidgetExceptions;
use App\Models\ProfileReview;
use App\Models\User;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Database\Eloquent\Builder;

class ReviewsUsersTrendChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use HandlesDashboardWidgetExceptions;

    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = [
        'xl' => 6,
    ];

    protected ?string $heading = 'Нові відгуки та користувачі';

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

            $reviewsSeries = $this->series(ProfileReview::query(), $range);
            $usersSeries = $this->series(User::query(), $range);

            return [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => sprintf('Відгуки (%s)', number_format(array_sum($reviewsSeries), 0, '.', ' ')),
                        'data' => $reviewsSeries,
                        'borderColor' => '#3B82F6',
                        'backgroundColor' => 'rgba(59,130,246,.12)',
                        'fill' => true,
                        'tension' => 0.35,
                    ],
                    [
                        'label' => sprintf('Користувачі (%s)', number_format(array_sum($usersSeries), 0, '.', ' ')),
                        'data' => $usersSeries,
                        'borderColor' => '#14B8A6',
                        'backgroundColor' => 'rgba(20,184,166,.08)',
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
