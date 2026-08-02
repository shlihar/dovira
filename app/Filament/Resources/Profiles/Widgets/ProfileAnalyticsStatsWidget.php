<?php

namespace App\Filament\Resources\Profiles\Widgets;

use App\Models\Profile;
use App\Models\ProfileEvent;
use App\Models\ProfileReview;
use App\Services\ProfileAnalyticsService;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

class ProfileAnalyticsStatsWidget extends StatsOverviewWidget
{
    protected int | string | array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public ?Profile $record = null;
    public string $period = 'last_7';
    public ?string $from = null;
    public ?string $to = null;

    protected function getStats(): array
    {
        if (! $this->record) {
            return [];
        }

        $service = app(ProfileAnalyticsService::class);
        $data = $service->analyticsSummary(
            $this->record,
            $this->period,
            $this->from,
            $this->to,
        );

        $viewsTrend = $data['metrics']['views'] ?? null;
        $uniqueTrend = $this->uniqueTrend($this->period, $this->from, $this->to);
        $websiteTrend = $data['metrics']['website_clicks'] ?? null;
        $contactTrend = $data['metrics']['contact_clicks'] ?? null;
        $reviewsTrend = $this->reviewsTrend($this->period, $this->from, $this->to);
        $ratingTrend = $this->ratingTrend($this->period, $this->from, $this->to);

        $currentCtr = (float) ($data['ctr'] ?? 0.0);
        $totalCtr = $this->totalCtr();
        $ctrTrend = $this->makeShareTrend($currentCtr, $totalCtr, $this->ctrSparkline($this->period, $this->from, $this->to));

        $popularityCurrent = ((int) ($viewsTrend['current'] ?? 0) * 1)
            + ((int) ($websiteTrend['current'] ?? 0) * 3)
            + ((int) ($contactTrend['current'] ?? 0) * 5)
            + ((int) ($reviewsTrend['current'] ?? 0) * 4)
            + ((float) ($ratingTrend['current'] ?? 0) * 10);

        $popularityTrend = $this->makeShareTrend(
            $popularityCurrent,
            (float) $this->record->popularity_score,
            array_values(array_map('intval', $viewsTrend['sparkline'] ?? [])),
        );

        return [
            $this->makeStat('Перегляди профілю', (float) ($viewsTrend['current'] ?? 0), $this->makeShareTrend((float) ($viewsTrend['current'] ?? 0), (float) $this->record->views_count, $viewsTrend['sparkline'] ?? [])),
            $this->makeStat('Унікальні відвідувачі', (float) ($uniqueTrend['current'] ?? 0), $this->makeShareTrend((float) ($uniqueTrend['current'] ?? 0), (float) $this->record->unique_views_count, $uniqueTrend['sparkline'] ?? [])),
            $this->makeStat('Кліки на сайт', (float) ($websiteTrend['current'] ?? 0), $this->makeShareTrend((float) ($websiteTrend['current'] ?? 0), (float) $this->record->website_clicks_count, $websiteTrend['sparkline'] ?? [])),
            $this->makeStat('Кліки на контакти', (float) ($contactTrend['current'] ?? 0), $this->makeShareTrend((float) ($contactTrend['current'] ?? 0), (float) $this->record->contact_clicks_count, $contactTrend['sparkline'] ?? [])),
            $this->makeStat('CTR', $currentCtr, $ctrTrend, true),
            $this->makeStat('Середній рейтинг', (float) ($ratingTrend['current'] ?? 0), $this->makeShareTrend((float) ($ratingTrend['current'] ?? 0), (float) $this->record->rating_avg, $ratingTrend['sparkline'] ?? []), false, 1),
            $this->makeStat('Кількість відгуків', (float) ($reviewsTrend['current'] ?? 0), $this->makeShareTrend((float) ($reviewsTrend['current'] ?? 0), (float) $this->record->reviews_count, $reviewsTrend['sparkline'] ?? [])),
            $this->makeStat('Популярність (score)', (float) $popularityCurrent, $popularityTrend, false, 2),
        ];
    }

    #[On('profile-analytics-filters-updated')]
    public function onFiltersUpdated(string $period, ?string $from = null, ?string $to = null): void
    {
        $this->period = $period;
        $this->from = $from;
        $this->to = $to;
    }

    private function makeStat(string $label, int|float $value, ?array $trend = null, bool $isPercent = false, int $decimals = 0): Stat
    {
        $formatted = $isPercent
            ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') . '%'
            : number_format((float) $value, $decimals, '.', ' ');

        $stat = Stat::make($label, $formatted);

        if (! is_array($trend)) {
            return $stat;
        }

        $isUp = (bool) ($trend['is_up'] ?? true);
        $diffPercent = (float) ($trend['diff_percent'] ?? 0);
        $description = sprintf('%s%s%% за період', $isUp ? '+' : '', number_format($diffPercent, 1, '.', ''));
        $description = str_replace('за період', 'від загального', $description);

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

    private function previousCtr(?array $viewsTrend, ?array $websiteTrend, ?array $contactTrend): float
    {
        $views = (int) ($viewsTrend['previous'] ?? 0);
        if ($views <= 0) {
            return 0.0;
        }

        $clicks = (int) ($websiteTrend['previous'] ?? 0) + (int) ($contactTrend['previous'] ?? 0);

        return round(($clicks / $views) * 100, 2);
    }

    private function makeTrend(float $current, float $previous, array $sparkline = []): array
    {
        $diff = $current - $previous;
        $diffPercent = $previous > 0 ? ($diff / $previous) * 100 : ($current > 0 ? 100.0 : 0.0);

        return [
            'is_up' => $diff >= 0,
            'diff_percent' => round($diffPercent, 1),
            'current' => $current,
            'previous' => $previous,
            'sparkline' => $sparkline,
        ];
    }

    private function makeShareTrend(float $current, float $total, array $sparkline = []): array
    {
        $share = $total > 0 ? ($current / $total) * 100 : 0.0;

        return [
            'is_up' => $current > 0,
            'diff_percent' => round($share, 1),
            'current' => $current,
            'previous' => 0,
            'sparkline' => $sparkline,
        ];
    }

    private function totalCtr(): float
    {
        $views = max(0, (int) $this->record?->views_count);
        if ($views === 0) {
            return 0.0;
        }

        $clicks = max(0, (int) $this->record?->website_clicks_count) + max(0, (int) $this->record?->contact_clicks_count);

        return round(($clicks / $views) * 100, 2);
    }

    private function resolveRange(string $period, ?string $from, ?string $to): array
    {
        [$fromAt, $toAt] = app(ProfileAnalyticsService::class)->resolvePeriod($period, $from, $to);

        if (! $fromAt || ! $toAt) {
            $toAt = CarbonImmutable::now()->endOfDay();
            $fromAt = $toAt->subDays(6)->startOfDay();
        }

        $days = max(1, $fromAt->diffInDays($toAt) + 1);
        $previousEnd = $fromAt->subSecond();
        $previousStart = $previousEnd->subDays($days - 1)->startOfDay();

        return [$fromAt, $toAt, $previousStart, $previousEnd];
    }

    private function uniqueTrend(string $period, ?string $from, ?string $to): array
    {
        [$fromAt, $toAt, $previousStart, $previousEnd] = $this->resolveRange($period, $from, $to);

        $base = ProfileEvent::query()
            ->where('profile_id', $this->record->id)
            ->where('event_type', ProfileAnalyticsService::EVENT_PROFILE_VIEW)
            ->whereNotNull('visitor_id');

        $current = (clone $base)->whereBetween('created_at', [$fromAt, $toAt])->distinct('visitor_id')->count('visitor_id');
        $previous = (clone $base)->whereBetween('created_at', [$previousStart, $previousEnd])->distinct('visitor_id')->count('visitor_id');

        $rows = (clone $base)
            ->selectRaw('DATE(created_at) as day, COUNT(DISTINCT visitor_id) as total')
            ->whereBetween('created_at', [$fromAt, $toAt])
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get()
            ->pluck('total', 'day');

        $spark = [];
        $days = max(1, $fromAt->diffInDays($toAt) + 1);
        for ($i = 0; $i < $days; $i++) {
            $day = $fromAt->addDays($i)->toDateString();
            $spark[] = (int) ($rows[$day] ?? 0);
        }

        return $this->makeTrend((float) $current, (float) $previous, $spark);
    }

    private function reviewsTrend(string $period, ?string $from, ?string $to): array
    {
        [$fromAt, $toAt, $previousStart, $previousEnd] = $this->resolveRange($period, $from, $to);

        $base = ProfileReview::query()
            ->where('profile_id', $this->record->id)
            ->where('status', 'published');

        $current = (clone $base)->whereBetween('created_at', [$fromAt, $toAt])->count();
        $previous = (clone $base)->whereBetween('created_at', [$previousStart, $previousEnd])->count();

        $spark = $this->sparklineByDays(clone $base, $fromAt, $toAt);

        return $this->makeTrend((float) $current, (float) $previous, $spark);
    }

    private function ratingTrend(string $period, ?string $from, ?string $to): array
    {
        [$fromAt, $toAt, $previousStart, $previousEnd] = $this->resolveRange($period, $from, $to);

        $base = ProfileReview::query()
            ->where('profile_id', $this->record->id)
            ->where('status', 'published');

        $current = (float) ((clone $base)->whereBetween('created_at', [$fromAt, $toAt])->avg('rating') ?? 0);
        $previous = (float) ((clone $base)->whereBetween('created_at', [$previousStart, $previousEnd])->avg('rating') ?? 0);

        $rows = (clone $base)
            ->selectRaw('DATE(created_at) as day, AVG(rating) as avg_rating')
            ->whereBetween('created_at', [$fromAt, $toAt])
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get()
            ->pluck('avg_rating', 'day');

        $spark = [];
        $days = max(1, $fromAt->diffInDays($toAt) + 1);
        for ($i = 0; $i < $days; $i++) {
            $day = $fromAt->addDays($i)->toDateString();
            $spark[] = (int) round(((float) ($rows[$day] ?? 0)) * 20);
        }

        return $this->makeTrend($current, $previous, $spark);
    }

    private function popularityTrend(?array $viewsTrend, ?array $websiteTrend, ?array $contactTrend, ?array $reviewsTrend, ?array $ratingTrend): array
    {
        $current = ((int) ($viewsTrend['current'] ?? 0) * 1)
            + ((int) ($websiteTrend['current'] ?? 0) * 3)
            + ((int) ($contactTrend['current'] ?? 0) * 5)
            + ((int) ($reviewsTrend['current'] ?? 0) * 4)
            + ((float) ($ratingTrend['current'] ?? 0) * 10);

        $previous = ((int) ($viewsTrend['previous'] ?? 0) * 1)
            + ((int) ($websiteTrend['previous'] ?? 0) * 3)
            + ((int) ($contactTrend['previous'] ?? 0) * 5)
            + ((int) ($reviewsTrend['previous'] ?? 0) * 4)
            + ((float) ($ratingTrend['previous'] ?? 0) * 10);

        $spark = $viewsTrend['sparkline'] ?? [];

        return $this->makeTrend((float) $current, (float) $previous, array_values(array_map('intval', $spark)));
    }

    private function ctrSparkline(string $period, ?string $from, ?string $to): array
    {
        [$fromAt, $toAt] = $this->resolveRange($period, $from, $to);

        $viewsRows = ProfileEvent::query()
            ->where('profile_id', $this->record->id)
            ->where('event_type', ProfileAnalyticsService::EVENT_PROFILE_VIEW)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->whereBetween('created_at', [$fromAt, $toAt])
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get()
            ->pluck('total', 'day');

        $clickRows = ProfileEvent::query()
            ->where('profile_id', $this->record->id)
            ->whereIn('event_type', array_merge(
                ProfileAnalyticsService::WEBSITE_CLICK_EVENTS,
                ProfileAnalyticsService::CONTACT_CLICK_EVENTS
            ))
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->whereBetween('created_at', [$fromAt, $toAt])
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get()
            ->pluck('total', 'day');

        $days = max(1, $fromAt->diffInDays($toAt) + 1);
        $spark = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $fromAt->addDays($i)->toDateString();
            $views = (int) ($viewsRows[$day] ?? 0);
            $clicks = (int) ($clickRows[$day] ?? 0);
            $spark[] = $views > 0 ? (int) round(($clicks / $views) * 100) : 0;
        }

        return $spark;
    }

    private function sparklineByDays(Builder $query, CarbonImmutable $fromAt, CarbonImmutable $toAt): array
    {
        $rows = $query
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->whereBetween('created_at', [$fromAt, $toAt])
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get()
            ->pluck('total', 'day');

        $points = [];
        $days = max(1, $fromAt->diffInDays($toAt) + 1);
        for ($i = 0; $i < $days; $i++) {
            $day = $fromAt->addDays($i)->toDateString();
            $points[] = (int) ($rows[$day] ?? 0);
        }

        return $points;
    }
}
