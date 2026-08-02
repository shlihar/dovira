<?php

namespace App\Services;

use App\Models\Profile;
use App\Models\ProfileEvent;
use App\Models\Category;
use App\Support\CategoryHierarchy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProfileAnalyticsService
{
    public const EVENT_PROFILE_VIEW = 'profile_view';

    public const WEBSITE_CLICK_EVENTS = [
        'website_click',
    ];

    public const CONTACT_CLICK_EVENTS = [
        'phone_click',
        'email_click',
        'telegram_click',
        'viber_click',
        'whatsapp_click',
        'instagram_click',
        'facebook_click',
        'map_click',
    ];

    /**
     * Навігаційні події воронки профілю (не кліки-контакти): відкриття вкладки
     * «Інформація», відкриття та фактичний перегляд досьє. Для аналізу, що
     * робив користувач до звернення (особливо ті, хто прийшов із розсилки).
     */
    public const FUNNEL_EVENTS = [
        'info_tab_view',
        'dossier_open',
        'dossier_view',
    ];

    public const ALLOWED_EVENT_TYPES = [
        'profile_view',
        'website_click',
        'phone_click',
        'email_click',
        'telegram_click',
        'viber_click',
        'whatsapp_click',
        'instagram_click',
        'facebook_click',
        'map_click',
        'info_tab_view',
        'dossier_open',
        'dossier_view',
    ];

    public function ensureVisitorId(Request $request): string
    {
        $cookie = (string) $request->cookie('dovira_visitor_id', '');

        return $cookie !== '' ? $cookie : (string) Str::uuid();
    }

    public function track(Profile $profile, array $payload): ProfileEvent
    {
        $eventType = (string) ($payload['event_type'] ?? '');
        if (!in_array($eventType, self::ALLOWED_EVENT_TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported profile event type.');
        }

        $visitorId = isset($payload['visitor_id']) ? trim((string) $payload['visitor_id']) : null;
        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : null;
        $ipHash = $this->cleanNullable($payload['ip_hash'] ?? null);

        // Guard against accidental duplicate frontend calls on initial page open.
        if ($eventType === self::EVENT_PROFILE_VIEW) {
            $recentView = ProfileEvent::query()
                ->where('profile_id', $profile->id)
                ->where('event_type', self::EVENT_PROFILE_VIEW)
                ->where('created_at', '>=', now()->subSeconds(15))
                ->when(!empty($visitorId), fn (Builder $query) => $query->where('visitor_id', $visitorId))
                ->when(empty($visitorId) && !empty($userId), fn (Builder $query) => $query->where('user_id', $userId))
                ->when(empty($visitorId) && empty($userId) && !empty($ipHash), fn (Builder $query) => $query->where('ip_hash', $ipHash))
                ->latest('id')
                ->first();

            if ($recentView) {
                return $recentView;
            }
        }

        $isUniqueView = false;
        if ($eventType === self::EVENT_PROFILE_VIEW && !empty($visitorId)) {
            $isUniqueView = ! ProfileEvent::query()
                ->where('profile_id', $profile->id)
                ->where('event_type', self::EVENT_PROFILE_VIEW)
                ->where('visitor_id', $visitorId)
                ->where('created_at', '>=', now()->subDay())
                ->exists();
        }

        $event = ProfileEvent::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $userId,
            'visitor_id' => $visitorId,
            'event_type' => $eventType,
            'source' => $this->cleanNullable($payload['source'] ?? null),
            'internal_source' => $this->cleanNullable($payload['internal_source'] ?? null),
            'referrer' => $this->cleanNullable($payload['referrer'] ?? null),
            'utm_source' => $this->cleanNullable($payload['utm_source'] ?? null),
            'utm_medium' => $this->cleanNullable($payload['utm_medium'] ?? null),
            'utm_campaign' => $this->cleanNullable($payload['utm_campaign'] ?? null),
            'utm_content' => $this->cleanNullable($payload['utm_content'] ?? null),
            'utm_term' => $this->cleanNullable($payload['utm_term'] ?? null),
            'target_url' => $this->cleanNullable($payload['target_url'] ?? null),
            'device_type' => $this->cleanNullable($payload['device_type'] ?? null),
            'country' => $this->cleanNullable($payload['country'] ?? null),
            'city' => $this->cleanNullable($payload['city'] ?? null),
            'ip_hash' => $ipHash,
            'user_agent' => $this->cleanNullable($payload['user_agent'] ?? null),
        ]);

        if ($eventType === self::EVENT_PROFILE_VIEW) {
            $profile->increment('views_count');
            if ($isUniqueView) {
                $profile->increment('unique_views_count');
            }
        }

        if (in_array($eventType, self::WEBSITE_CLICK_EVENTS, true)) {
            $profile->increment('website_clicks_count');
        }

        if (in_array($eventType, self::CONTACT_CLICK_EVENTS, true)) {
            $profile->increment('contact_clicks_count');
        }

        $this->recalculatePopularityScore($profile);

        return $event;
    }

    public function recalculatePopularityScore(Profile $profile): void
    {
        $score = ($profile->views_count * 1)
            + ($profile->website_clicks_count * 3)
            + ($profile->contact_clicks_count * 5)
            + ($profile->reviews_count * 4)
            + (((float) $profile->rating_avg) * 10);

        $profile->forceFill([
            'popularity_score' => round($score, 2),
        ])->saveQuietly();
    }

    /**
     * @return array{
     *   rank:?int,
     *   total:int,
     *   city:?string,
     *   context_type:?string,
     *   context_name:?string
     * }
     */
    public function popularityRankInLocalContext(Profile $profile): array
    {
        $selection = CategoryHierarchy::selectionFromProfile($profile);
        $contextCategoryId = $selection['subcategory_id'] ?: $selection['category_id'];
        $contextType = $selection['subcategory_id'] ? 'subcategory' : ($selection['category_id'] ? 'category' : null);
        $city = trim((string) ($profile->city ?? ''));

        if (! $contextCategoryId || $city === '') {
            return [
                'rank' => null,
                'total' => 0,
                'city' => $city !== '' ? $city : null,
                'context_type' => $contextType,
                'context_name' => null,
            ];
        }

        $contextCategory = Category::query()->find($contextCategoryId);
        if (! $contextCategory) {
            return [
                'rank' => null,
                'total' => 0,
                'city' => $city,
                'context_type' => $contextType,
                'context_name' => null,
            ];
        }

        $rankedIds = Profile::query()
            ->where('status', 'active')
            ->where('is_published', true)
            ->where('show_in_catalog', true)
            ->whereRaw('lower(city) = ?', [mb_strtolower($city)])
            ->whereHas('categories', fn (Builder $categories) => $categories->whereKey($contextCategoryId))
            ->orderByDesc('popularity_score')
            ->orderByDesc('rating_avg')
            ->orderByDesc('reviews_count')
            ->orderBy('id')
            ->pluck('id');

        $total = $rankedIds->count();
        $position = $rankedIds->search((int) $profile->id, true);

        return [
            'rank' => $position === false ? null : ($position + 1),
            'total' => $total,
            'city' => $city,
            'context_type' => $contextType,
            'context_name' => (string) $contextCategory->name,
        ];
    }

    public function resolvePeriod(string $period, ?string $customFrom = null, ?string $customTo = null): array
    {
        $now = CarbonImmutable::now();
        $period = trim($period);

        return match ($period) {
            'today' => [$now->startOfDay(), $now->endOfDay(), 'Сьогодні'],
            'last_7' => [$now->subDays(6)->startOfDay(), $now->endOfDay(), 'Останні 7 днів'],
            'last_30' => [$now->subDays(29)->startOfDay(), $now->endOfDay(), 'Останні 30 днів'],
            'last_90' => [$now->subDays(89)->startOfDay(), $now->endOfDay(), 'Останні 90 днів'],
            'custom' => [
                $customFrom ? CarbonImmutable::parse($customFrom)->startOfDay() : $now->subDays(6)->startOfDay(),
                $customTo ? CarbonImmutable::parse($customTo)->endOfDay() : $now->endOfDay(),
                'Кастомний період',
            ],
            default => [null, null, 'Весь час'],
        };
    }

    public function analyticsSummary(Profile $profile, string $period = 'last_7', ?string $from = null, ?string $to = null): array
    {
        [$fromAt, $toAt, $periodLabel] = $this->resolvePeriod($period, $from, $to);

        $eventsQuery = ProfileEvent::query()->where('profile_id', $profile->id);
        if ($fromAt && $toAt) {
            $eventsQuery->whereBetween('created_at', [$fromAt, $toAt]);
        }

        $views = (clone $eventsQuery)->where('event_type', self::EVENT_PROFILE_VIEW)->count();
        $unique = (clone $eventsQuery)
            ->where('event_type', self::EVENT_PROFILE_VIEW)
            ->whereNotNull('visitor_id')
            ->distinct('visitor_id')
            ->count('visitor_id');
        $websiteClicks = (clone $eventsQuery)->whereIn('event_type', self::WEBSITE_CLICK_EVENTS)->count();
        $contactClicks = (clone $eventsQuery)->whereIn('event_type', self::CONTACT_CLICK_EVENTS)->count();
        $ctr = $views > 0 ? round((($websiteClicks + $contactClicks) / $views) * 100, 2) : 0.0;

        $metrics = [
            'views' => $this->metricTrend($profile->id, [self::EVENT_PROFILE_VIEW], $fromAt, $toAt),
            'website_clicks' => $this->metricTrend($profile->id, self::WEBSITE_CLICK_EVENTS, $fromAt, $toAt),
            'contact_clicks' => $this->metricTrend($profile->id, self::CONTACT_CLICK_EVENTS, $fromAt, $toAt),
            'all_clicks' => $this->metricTrend(
                $profile->id,
                array_values(array_unique(array_merge(self::WEBSITE_CLICK_EVENTS, self::CONTACT_CLICK_EVENTS))),
                $fromAt,
                $toAt
            ),
        ];

        return [
            'period' => [
                'key' => $period,
                'label' => $periodLabel,
                'short_label' => $this->shortPeriodLabel($period, $periodLabel),
                'from' => $fromAt,
                'to' => $toAt,
                'days_count' => $fromAt && $toAt ? (int) max(1, $fromAt->startOfDay()->diffInDays($toAt->startOfDay()) + 1) : 14,
            ],
            'views' => $views,
            'unique' => $unique,
            'website_clicks' => $websiteClicks,
            'contact_clicks' => $contactClicks,
            'ctr' => $ctr,
            'rating_avg' => (float) $profile->rating_avg,
            'reviews_count' => (int) $profile->reviews_count,
            'metrics' => $metrics,
            'device_types' => $this->topDimension(clone $eventsQuery, 'device_type'),
            'sources_timeline' => $this->sourceTimeline($profile->id, $fromAt, $toAt),
        ];
    }

    private function metricTrend(int $profileId, array $eventTypes, ?CarbonImmutable $fromAt, ?CarbonImmutable $toAt): array
    {
        $base = ProfileEvent::query()
            ->where('profile_id', $profileId)
            ->whereIn('event_type', $eventTypes);

        if (! $fromAt || ! $toAt) {
            $end = CarbonImmutable::now()->endOfDay();
            $start = $end->subDays(13)->startOfDay();
            $current = (clone $base)->whereBetween('created_at', [$start, $end])->count();

            return [
                'current' => (int) $current,
                'previous' => 0,
                'diff_percent' => $current > 0 ? 100.0 : 0.0,
                'is_up' => $current >= 0,
                'sparkline' => $this->sparklineByDays(clone $base, $start, $end),
            ];
        }

        $days = max(1, $fromAt->startOfDay()->diffInDays($toAt->startOfDay()) + 1);
        $prevStart = $fromAt->subDays($days);
        $prevEnd = $fromAt->subSecond();

        $current = (clone $base)->whereBetween('created_at', [$fromAt, $toAt])->count();
        $previous = (clone $base)->whereBetween('created_at', [$prevStart, $prevEnd])->count();
        $diff = $current - $previous;
        $diffPercent = $previous > 0 ? round(($diff / $previous) * 100, 1) : ($current > 0 ? 100.0 : 0.0);

        return [
            'current' => (int) $current,
            'previous' => (int) $previous,
            'diff_percent' => (float) $diffPercent,
            'is_up' => $diff >= 0,
            'sparkline' => $this->sparklineByDays(clone $base, $fromAt, $toAt),
        ];
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

        $days = max(1, $fromAt->startOfDay()->diffInDays($toAt->startOfDay()) + 1);
        $start = $fromAt->startOfDay();

        $points = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $start->addDays($i)->toDateString();
            $points[] = (int) ($rows[$day] ?? 0);
        }

        return $points;
    }

    private function shortPeriodLabel(string $period, string $fallback): string
    {
        return match ($period) {
            'today' => 'За сьогодні',
            'last_7' => 'За 7 днів',
            'last_30' => 'За 30 днів',
            'last_90' => 'За 90 днів',
            default => $fallback,
        };
    }

    private function sourceTimeline(int $profileId, ?CarbonImmutable $fromAt, ?CarbonImmutable $toAt): array
    {
        if (! $fromAt || ! $toAt) {
            $toAt = CarbonImmutable::now()->endOfDay();
            $fromAt = $toAt->subDays(6)->startOfDay();
        }

        $query = ProfileEvent::query()
            ->where('profile_id', $profileId)
            ->where('event_type', self::EVENT_PROFILE_VIEW);

        $query->whereBetween('created_at', [$fromAt, $toAt]);

        $rows = $query
            ->selectRaw("DATE(created_at) as event_date")
            ->selectRaw("SUM(CASE WHEN LOWER(COALESCE(source,'')) LIKE '%google%' THEN 1 ELSE 0 END) as google_qty")
            ->selectRaw("SUM(CASE WHEN LOWER(COALESCE(source,'')) LIKE '%facebook%' OR LOWER(COALESCE(source,'')) LIKE '%fb%' THEN 1 ELSE 0 END) as facebook_qty")
            ->selectRaw("SUM(CASE WHEN LOWER(COALESCE(internal_source,'')) NOT IN ('', 'direct') THEN 1 ELSE 0 END) as internal_qty")
            ->selectRaw("SUM(CASE WHEN (LOWER(COALESCE(source,'')) NOT LIKE '%google%' AND LOWER(COALESCE(source,'')) NOT LIKE '%facebook%' AND LOWER(COALESCE(source,'')) NOT LIKE '%fb%') AND LOWER(COALESCE(internal_source,'')) IN ('', 'direct') THEN 1 ELSE 0 END) as unknown_qty")
            ->groupBy('event_date')
            ->orderBy('event_date')
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            $day = CarbonImmutable::parse((string) $row->event_date)->toDateString();
            $byDay[$day] = [
                'google' => (int) $row->google_qty,
                'facebook' => (int) $row->facebook_qty,
                'internal' => (int) $row->internal_qty,
                'unknown' => (int) $row->unknown_qty,
            ];
        }

        $labels = [];
        $google = [];
        $facebook = [];
        $internal = [];
        $unknown = [];

        $days = max(1, $fromAt->diffInDays($toAt) + 1);
        for ($i = 0; $i < $days; $i++) {
            $date = $fromAt->addDays($i);
            $day = $date->toDateString();
            $labels[] = $date->format('d.m');
            $google[] = (int) ($byDay[$day]['google'] ?? 0);
            $facebook[] = (int) ($byDay[$day]['facebook'] ?? 0);
            $internal[] = (int) ($byDay[$day]['internal'] ?? 0);
            $unknown[] = (int) ($byDay[$day]['unknown'] ?? 0);
        }

        return [
            'labels' => $labels,
            'series' => [
                'google' => $google,
                'facebook' => $facebook,
                'internal' => $internal,
                'unknown' => $unknown,
            ],
        ];
    }

    private function topDimension(Builder $query, string $field): array
    {
        return $query
            ->whereNotNull($field)
            ->where($field, '!=', '')
            ->selectRaw($field . ' as label, COUNT(*) as qty')
            ->groupBy($field)
            ->orderByDesc('qty')
            ->limit(8)
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'qty' => (int) $row->qty])
            ->all();
    }

    private function cleanNullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
