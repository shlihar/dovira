<?php

namespace App\Filament\Widgets;

use App\Filament\Support\AnalyticsRange;
use App\Filament\Widgets\Concerns\HandlesDashboardWidgetExceptions;
use App\Models\SitePageEvent;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Str;

class SiteTrafficSourcesTrendChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use HandlesDashboardWidgetExceptions;

    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = 'full';

    protected ?string $heading = 'Джерела переходів на сайт';
    
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

            $rows = SitePageEvent::query()
                ->selectRaw('DATE(created_at) as day, source, internal_source, referrer, COUNT(*) as total')
                ->where('event_type', 'site_page_view')
                ->whereBetween('created_at', [$range['start'], $range['end']])
                ->groupByRaw('DATE(created_at), source, internal_source, referrer')
                ->orderByRaw('DATE(created_at)')
                ->get();

            $knownSources = [
                'Google',
                'Facebook',
                'Instagram',
                'Telegram',
                'Direct',
                'Referral',
                'Внутрішній перехід',
                'Невідомо',
            ];

            $matrix = [];
            foreach ($knownSources as $source) {
                $matrix[$source] = array_fill(0, $range['days'], 0);
            }

            foreach ($rows as $row) {
                $sourceLabel = $this->detectSourceLabel(
                    (string) ($row->source ?? ''),
                    (string) ($row->internal_source ?? ''),
                    (string) ($row->referrer ?? '')
                );

                if (! isset($matrix[$sourceLabel])) {
                    $matrix[$sourceLabel] = array_fill(0, $range['days'], 0);
                }

                $dayIndex = $range['start']->diffInDays((string) $row->day);
                if ($dayIndex >= 0 && $dayIndex < $range['days']) {
                    $matrix[$sourceLabel][$dayIndex] += (int) $row->total;
                }
            }

            $palette = [
                'Google' => ['#3B82F6', 'rgba(59,130,246,.10)'],
                'Facebook' => ['#6366F1', 'rgba(99,102,241,.10)'],
                'Instagram' => ['#A855F7', 'rgba(168,85,247,.10)'],
                'Telegram' => ['#06B6D4', 'rgba(6,182,212,.10)'],
                'Direct' => ['#22C55E', 'rgba(34,197,94,.10)'],
                'Referral' => ['#F59E0B', 'rgba(245,158,11,.10)'],
                'Внутрішній перехід' => ['#14B8A6', 'rgba(20,184,166,.10)'],
                'Невідомо' => ['#64748B', 'rgba(100,116,139,.10)'],
            ];

            $datasets = [];
            foreach ($matrix as $label => $values) {
                $totalForSource = array_sum($values);
                if ($totalForSource === 0) {
                    continue;
                }

                [$borderColor, $backgroundColor] = $palette[$label] ?? ['#94A3B8', 'rgba(148,163,184,.10)'];

                $datasets[] = [
                    'label' => sprintf('%s (%s)', $label, number_format($totalForSource, 0, '.', ' ')),
                    'data' => $values,
                    'borderColor' => $borderColor,
                    'backgroundColor' => $backgroundColor,
                    'fill' => 'stack',
                    'stack' => 'traffic',
                    'tension' => 0.35,
                    'pointRadius' => 0,
                    'pointHoverRadius' => 3,
                    'borderWidth' => 2,
                ];
            }

            return [
                'labels' => $labels,
                'datasets' => $datasets,
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
                'x' => ['stacked' => true],
                'y' => ['beginAtZero' => true, 'stacked' => true],
            ],
        ];
    }

    private function detectSourceLabel(string $source, string $internalSource, string $referrer): string
    {
        $sourceValue = Str::lower(trim($source));
        $internalValue = Str::lower(trim($internalSource));
        $referrerValue = Str::lower(trim($referrer));

        if ($sourceValue !== '') {
            if (Str::contains($sourceValue, ['google'])) {
                return 'Google';
            }

            if (Str::contains($sourceValue, ['facebook', 'fb'])) {
                return 'Facebook';
            }

            if (Str::contains($sourceValue, ['instagram', 'ig'])) {
                return 'Instagram';
            }

            if (Str::contains($sourceValue, ['telegram', 'tg'])) {
                return 'Telegram';
            }

            if (Str::contains($sourceValue, ['direct'])) {
                return 'Direct';
            }

            return ucfirst($sourceValue);
        }

        if ($internalValue !== '') {
            return 'Внутрішній перехід';
        }

        if ($referrerValue !== '') {
            if (Str::contains($referrerValue, 'google.')) {
                return 'Google';
            }

            if (Str::contains($referrerValue, 'facebook.')) {
                return 'Facebook';
            }

            if (Str::contains($referrerValue, 'instagram.')) {
                return 'Instagram';
            }

            if (Str::contains($referrerValue, 't.me')) {
                return 'Telegram';
            }

            return 'Referral';
        }

        return 'Невідомо';
    }
}
