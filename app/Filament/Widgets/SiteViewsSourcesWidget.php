<?php

namespace App\Filament\Widgets;

use App\Filament\Support\AnalyticsRange;
use App\Filament\Widgets\Concerns\HandlesDashboardWidgetExceptions;
use App\Models\SitePageEvent;
use App\Support\TrafficSourceClassifier;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Livewire\Attributes\On;

class SiteViewsSourcesWidget extends Widget
{
    use InteractsWithPageFilters;
    use HandlesDashboardWidgetExceptions;

    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    protected string $view = 'filament.widgets.site-views-sources-widget';

    public bool $isOpen = false;

    #[On('dashboard-show-site-views-sources')]
    public function showSources(): void
    {
        $this->isOpen = true;
    }

    public function hideSources(): void
    {
        $this->isOpen = false;
    }

    public function getTotalViewsProperty(): int
    {
        try {
            $range = AnalyticsRange::fromFilters($this->pageFilters ?? []);

            return (int) SitePageEvent::query()
                ->where('event_type', 'site_page_view')
                ->whereBetween('created_at', [$range['start'], $range['end']])
                ->count();
        } catch (\Throwable $exception) {
            $this->reportDashboardWidgetException('getTotalViewsProperty', $exception);

            return 0;
        }
    }

    /**
     * @return array<int, array{label:string,count:int,percent:float}>
     */
    public function getSourcesProperty(): array
    {
        try {
            $range = AnalyticsRange::fromFilters($this->pageFilters ?? []);

            $rows = SitePageEvent::query()
                ->selectRaw('source, internal_source, referrer, COUNT(*) as total')
                ->where('event_type', 'site_page_view')
                ->whereBetween('created_at', [$range['start'], $range['end']])
                ->groupBy('source', 'internal_source', 'referrer')
                ->get();

            $grouped = [];
            $total = 0;

            foreach ($rows as $row) {
                $count = (int) $row->total;
                $label = TrafficSourceClassifier::label(
                    (string) ($row->source ?? ''),
                    (string) ($row->internal_source ?? ''),
                    (string) ($row->referrer ?? '')
                );

                $grouped[$label] = ($grouped[$label] ?? 0) + $count;
                $total += $count;
            }

            arsort($grouped);

            $result = [];
            foreach ($grouped as $label => $count) {
                $result[] = [
                    'label' => (string) $label,
                    'count' => (int) $count,
                    'percent' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
                ];
            }

            return $result;
        } catch (\Throwable $exception) {
            $this->reportDashboardWidgetException('getSourcesProperty', $exception);

            return [];
        }
    }

}
