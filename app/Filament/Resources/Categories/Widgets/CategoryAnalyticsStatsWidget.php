<?php

namespace App\Filament\Resources\Categories\Widgets;

use App\Models\Category;
use App\Services\CategoryAnalyticsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CategoryAnalyticsStatsWidget extends StatsOverviewWidget
{
    protected int | string | array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public ?Category $record = null;
    public string $period = 'last_7';
    public ?string $from = null;
    public ?string $to = null;

    protected function getStats(): array
    {
        if (! $this->record) {
            return [];
        }

        $service = app(CategoryAnalyticsService::class);
        $data = $service->analyticsSummary($this->record, $this->period, $this->from, $this->to);

        $viewsTrend = $data['metrics']['views'] ?? null;
        $reviewsTrend = $data['metrics']['reviews'] ?? null;
        $profilesTrend = $data['metrics']['profiles'] ?? null;
        $proProfilesTrend = $data['metrics']['pro_profiles'] ?? null;

        return [
            $this->makeStat('Перегляди категорії', (float) ($viewsTrend['current'] ?? 0), $this->makeShareTrend((float) ($viewsTrend['current'] ?? 0), (float) ($data['totals']['views'] ?? 0), $viewsTrend['sparkline'] ?? [])),
            $this->makeStat('Відгуки в категорії', (float) ($reviewsTrend['current'] ?? 0), $this->makeShareTrend((float) ($reviewsTrend['current'] ?? 0), (float) ($data['totals']['reviews'] ?? 0), $reviewsTrend['sparkline'] ?? [])),
            $this->makeStat('Профілі в категорії', (float) ($profilesTrend['current'] ?? 0), $this->makeShareTrend((float) ($profilesTrend['current'] ?? 0), (float) ($data['totals']['profiles'] ?? 0), $profilesTrend['sparkline'] ?? [])),
            $this->makeStat('PRO-профілі в категорії', (float) ($proProfilesTrend['current'] ?? 0), $this->makeShareTrend((float) ($proProfilesTrend['current'] ?? 0), (float) ($data['totals']['pro_profiles'] ?? 0), $proProfilesTrend['sparkline'] ?? [])),
        ];
    }

    private function makeStat(string $label, float $value, ?array $trend = null): Stat
    {
        $stat = Stat::make($label, number_format($value, 0, '.', ' '));

        if (! is_array($trend)) {
            return $stat;
        }

        $isUp = (bool) ($trend['is_up'] ?? true);
        $diffPercent = (float) ($trend['diff_percent'] ?? 0);
        $description = sprintf('%s%s%% від загального', $isUp ? '+' : '', number_format($diffPercent, 1, '.', ''));

        $sparkline = array_values(array_map('intval', $trend['sparkline'] ?? []));
        if (blank($sparkline)) {
            $sparkline = [0, 0, 0, 0, 0, 0, 0];
        }

        return $stat
            ->description($description)
            ->descriptionIcon($isUp ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($isUp ? 'success' : 'danger')
            ->chart($sparkline);
    }

    private function makeShareTrend(float $current, float $total, array $sparkline = []): array
    {
        $share = $total > 0 ? ($current / $total) * 100 : 0.0;

        return [
            'is_up' => $current > 0,
            'diff_percent' => round($share, 1),
            'sparkline' => $sparkline,
        ];
    }
}

