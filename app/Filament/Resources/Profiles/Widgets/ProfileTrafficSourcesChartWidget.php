<?php

namespace App\Filament\Resources\Profiles\Widgets;

use App\Models\Profile;
use App\Services\ProfileAnalyticsService;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Livewire\Attributes\On;

class ProfileTrafficSourcesChartWidget extends ChartWidget
{
    protected int | string | array $columnSpan = 'full';

    protected ?string $heading = 'Джерела переходів на профіль';

    protected ?string $maxHeight = '180px';

    protected ?string $pollingInterval = null;

    public ?Profile $record = null;
    public string $period = 'last_7';
    public ?string $from = null;
    public ?string $to = null;

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        if (! $this->record) {
            return [
                'labels' => [],
                'datasets' => [],
            ];
        }

        $data = app(ProfileAnalyticsService::class)->analyticsSummary(
            $this->record,
            $this->period,
            $this->from,
            $this->to,
        );
        $timeline = $data['sources_timeline'] ?? ['labels' => [], 'series' => []];
        $google = $timeline['series']['google'] ?? [];
        $facebook = $timeline['series']['facebook'] ?? [];
        $internal = $timeline['series']['internal'] ?? [];
        $unknown = $timeline['series']['unknown'] ?? [];

        return [
            'labels' => $timeline['labels'] ?? [],
            'datasets' => [
                [
                    'label' => sprintf('Google (%s)', number_format(array_sum($google), 0, '.', ' ')),
                    'data' => $google,
                    'borderColor' => '#3B82F6',
                    'backgroundColor' => 'rgba(59,130,246,.12)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
                [
                    'label' => sprintf('Facebook (%s)', number_format(array_sum($facebook), 0, '.', ' ')),
                    'data' => $facebook,
                    'borderColor' => '#2563EB',
                    'backgroundColor' => 'rgba(37,99,235,.10)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
                [
                    'label' => sprintf('Внутрішні переходи (%s)', number_format(array_sum($internal), 0, '.', ' ')),
                    'data' => $internal,
                    'borderColor' => '#14B8A6',
                    'backgroundColor' => 'rgba(20,184,166,.08)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
                [
                    'label' => sprintf('Невідомі (%s)', number_format(array_sum($unknown), 0, '.', ' ')),
                    'data' => $unknown,
                    'borderColor' => '#64748B',
                    'backgroundColor' => 'rgba(100,116,139,.08)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
            ],
        ];
    }

    #[On('profile-analytics-filters-updated')]
    public function onFiltersUpdated(string $period, ?string $from = null, ?string $to = null): void
    {
        $this->period = $period;
        $this->from = $from;
        $this->to = $to;

        $this->updateChartData();
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
}
