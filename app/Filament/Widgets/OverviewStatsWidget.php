<?php

namespace App\Filament\Widgets;

use App\Filament\Support\AnalyticsRange;
use App\Filament\Widgets\Concerns\HandlesDashboardWidgetExceptions;
use App\Models\Profile;
use App\Models\ProfileReview;
use App\Models\SitePageEvent;
use App\Models\ProSubscription;
use App\Models\ReviewReport;
use App\Models\User;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class OverviewStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;
    use HandlesDashboardWidgetExceptions;

    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    protected function getStats(): array
    {
        try {
            $range = AnalyticsRange::fromFilters($this->pageFilters ?? []);

            return [
                $this->makeStat(
                    'Перегляди сайту за період',
                    SitePageEvent::query()->where('event_type', 'site_page_view'),
                    fn (Builder $q) => $q->count(),
                    $range,
                    fn (Builder $q) => $q->whereBetween('created_at', [$range['start'], $range['end']])->count()
                )
                    ->descriptionIcon('heroicon-m-arrow-trending-up')
                    ->extraAttributes([
                        'class' => 'cursor-pointer',
                        'wire:click' => "\$dispatch('dashboard-show-site-views-sources')",
                        'title' => 'Показати джерела переходів',
                    ]),
                $this->makeStat(
                    'Профілі за період',
                    Profile::query(),
                    fn (Builder $q) => $q->count(),
                    $range,
                    fn (Builder $q) => $q->whereBetween('created_at', [$range['start'], $range['end']])->count()
                ),
                $this->makeStat(
                    'Відгуки за період',
                    ProfileReview::query(),
                    fn (Builder $q) => $q->count(),
                    $range,
                    fn (Builder $q) => $q->whereBetween('created_at', [$range['start'], $range['end']])->count()
                ),
                $this->makeStat(
                    'Користувачі за період',
                    User::query(),
                    fn (Builder $q) => $q->count(),
                    $range,
                    fn (Builder $q) => $q->whereBetween('created_at', [$range['start'], $range['end']])->count()
                ),
                $this->makeStat(
                    'Verified за період',
                    Profile::query()->where('is_verified', true),
                    fn (Builder $q) => $q->count(),
                    $range,
                    fn (Builder $q) => $q->whereBetween('created_at', [$range['start'], $range['end']])->count()
                ),
                $this->makeStat(
                    'PRO за період',
                    Profile::query()->where('is_pro', true),
                    fn (Builder $q) => $q->count(),
                    $range,
                    fn (Builder $q) => $q->whereBetween('created_at', [$range['start'], $range['end']])->count()
                ),
                $this->makeStat(
                    'Нові на модерації',
                    ProfileReview::query()->whereIn('status', ['pending', 'under_review']),
                    fn (Builder $q) => $q->count(),
                    $range,
                    fn (Builder $q) => $q->whereBetween('created_at', [$range['start'], $range['end']])->count()
                ),
                $this->makeStat(
                    'Скарги за період',
                    ReviewReport::query()->whereIn('status', ['open', 'new', 'in_review']),
                    fn (Builder $q) => $q->count(),
                    $range,
                    fn (Builder $q) => $q->whereBetween('created_at', [$range['start'], $range['end']])->count()
                ),
                $this->makeStat(
                    'Нові PRO за період',
                    ProSubscription::query()->where('status', 'active'),
                    fn (Builder $q) => $q->count(),
                    $range,
                    fn (Builder $q) => $q->whereBetween('created_at', [$range['start'], $range['end']])->count()
                ),
            ];
        } catch (\Throwable $exception) {
            $this->reportDashboardWidgetException('getStats', $exception);

            return [
                Stat::make('Dashboard тимчасово недоступний', '—')
                    ->description('Перевірте логи сервера або стан міграцій.')
                    ->descriptionIcon('heroicon-m-exclamation-triangle')
                    ->color('danger'),
            ];
        }
    }

    /**
     * @param  array{start:\Carbon\CarbonImmutable,end:\Carbon\CarbonImmutable,previousStart:\Carbon\CarbonImmutable,previousEnd:\Carbon\CarbonImmutable,days:int}  $range
     */
    protected function makeStat(
        string $label,
        Builder $baseQuery,
        \Closure $totalResolver,
        array $range,
        \Closure $periodResolver,
    ): Stat {
        $total = (int) $totalResolver(clone $baseQuery);
        $current = (int) $periodResolver(clone $baseQuery);
        $sharePercent = $total > 0
            ? round(($current / $total) * 100, 1)
            : 0.0;
        $description = sprintf('+%s%% від загального (%s)', number_format($sharePercent, 1, '.', ''), number_format($total, 0, '.', ' '));

        $sparkline = $this->buildSparkline(clone $baseQuery, $range);

        return Stat::make($label, number_format($current, 0, '.', ' '))
            ->description($description)
            ->descriptionIcon('heroicon-m-arrow-trending-up')
            ->color($current > 0 ? 'success' : 'gray')
            ->chart($sparkline);
    }

    /**
     * @param  array{start:\Carbon\CarbonImmutable,end:\Carbon\CarbonImmutable,days:int}  $range
     * @return array<int, int>
     */
    protected function buildSparkline(Builder $query, array $range): array
    {
        $rows = $query
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->whereBetween('created_at', [$range['start'], $range['end']])
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get()
            ->pluck('total', 'day');

        $values = [];
        for ($i = 0; $i < min($range['days'], 14); $i++) {
            $day = $range['start']->addDays($i)->toDateString();
            $values[] = (int) ($rows[$day] ?? 0);
        }

        return $values;
    }
}
