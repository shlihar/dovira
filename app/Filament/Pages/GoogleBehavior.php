<?php

namespace App\Filament\Pages;

use App\Filament\Support\AnalyticsRange;
use App\Models\Profile;
use App\Models\ProfileEvent;
use App\Models\SitePageEvent;
use App\Services\ProfileAnalyticsService;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

class GoogleBehavior extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.google-behavior';

    public string $period = 'last_30';

    public function getTitle(): string
    {
        return 'Google поведінка';
    }

    public static function getNavigationLabel(): string
    {
        return 'Google поведінка';
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return 'Аналітика';
    }

    /**
     * @return array{start:\Carbon\CarbonImmutable,end:\Carbon\CarbonImmutable,previousStart:\Carbon\CarbonImmutable,previousEnd:\Carbon\CarbonImmutable,days:int}
     */
    private function range(): array
    {
        return AnalyticsRange::fromFilters([
            'period' => $this->period,
        ]);
    }

    /**
     * @return Collection<int, string>
     */
    private function googleVisitorIds(): Collection
    {
        $range = $this->range();

        return SitePageEvent::query()
            ->where('event_type', 'site_page_view')
            ->whereBetween('created_at', [$range['start'], $range['end']])
            ->whereNotNull('visitor_id')
            ->where($this->googleSourceConstraint())
            ->distinct()
            ->pluck('visitor_id')
            ->filter()
            ->values();
    }

    /**
     * @return array<string, int>
     */
    public function summary(): array
    {
        $range = $this->range();
        $visitorIds = $this->googleVisitorIds();

        $siteEvents = $this->siteEventsForGoogleVisitors($visitorIds, $range);
        $profileEvents = $this->profileEventsForGoogleVisitors($visitorIds, $range);

        $contactEvents = array_values(array_unique(array_merge(
            ProfileAnalyticsService::WEBSITE_CLICK_EVENTS,
            ProfileAnalyticsService::CONTACT_CLICK_EVENTS,
        )));

        return [
            'visitors' => $visitorIds->count(),
            'google_page_views' => SitePageEvent::query()
                ->where('event_type', 'site_page_view')
                ->whereBetween('created_at', [$range['start'], $range['end']])
                ->where($this->googleSourceConstraint())
                ->count(),
            'site_searches' => (clone $siteEvents)->where('event_type', 'search_query')->count(),
            'searched_visitors' => (clone $siteEvents)->where('event_type', 'search_query')->distinct('visitor_id')->count('visitor_id'),
            'profile_views' => (clone $profileEvents)->where('event_type', ProfileAnalyticsService::EVENT_PROFILE_VIEW)->count(),
            'profile_visitors' => (clone $profileEvents)->where('event_type', ProfileAnalyticsService::EVENT_PROFILE_VIEW)->distinct('visitor_id')->count('visitor_id'),
            'contact_clicks' => (clone $profileEvents)->whereIn('event_type', $contactEvents)->count(),
            'review_starts' => (clone $siteEvents)->where('event_type', 'review_popup_open')->count(),
            'review_submits' => (clone $siteEvents)->where('event_type', 'review_submit_success')->count(),
        ];
    }

    /**
     * @return array<int, array{label:string,count:int,percent:int}>
     */
    public function landingPages(): array
    {
        $range = $this->range();
        $rows = SitePageEvent::query()
            ->where('event_type', 'site_page_view')
            ->whereBetween('created_at', [$range['start'], $range['end']])
            ->where($this->googleSourceConstraint())
            ->selectRaw('page_path, count(*) as views')
            ->groupBy('page_path')
            ->orderByDesc('views')
            ->limit(15)
            ->get();

        return $this->withPercent($rows, 'page_path', 'views');
    }

    /**
     * @return array<int, array{label:string,count:int,percent:int}>
     */
    public function searches(): array
    {
        $range = $this->range();
        $visitorIds = $this->googleVisitorIds();

        if ($visitorIds->isEmpty()) {
            return [];
        }

        $rows = $this->siteEventsForGoogleVisitors($visitorIds, $range)
            ->where('event_type', 'search_query')
            ->whereNotNull('event_label')
            ->where('event_label', '!=', '')
            ->selectRaw('event_label, count(*) as total')
            ->groupBy('event_label')
            ->orderByDesc('total')
            ->limit(20)
            ->get();

        return $this->withPercent($rows, 'event_label', 'total');
    }

    /**
     * @return array<int, array{label:string,count:int,percent:int}>
     */
    public function siteActions(): array
    {
        $range = $this->range();
        $visitorIds = $this->googleVisitorIds();

        if ($visitorIds->isEmpty()) {
            return [];
        }

        $rows = $this->siteEventsForGoogleVisitors($visitorIds, $range)
            ->whereIn('event_type', [
                'search_suggest_click',
                'catalog_filter_apply',
                'catalog_sort_change',
                'catalog_load_more',
                'review_popup_open',
                'review_submit_success',
                'cta_click',
            ])
            ->selectRaw("event_type, coalesce(nullif(event_label, ''), '—') as label, count(*) as total")
            ->groupBy('event_type', 'label')
            ->orderByDesc('total')
            ->limit(20)
            ->get()
            ->map(fn ($row) => (object) [
                'label' => $this->siteEventLabel((string) $row->event_type, (string) $row->label),
                'total' => (int) $row->total,
            ]);

        return $this->withPercent($rows, 'label', 'total');
    }

    /**
     * @return array<int, array{name:string,slug:?string,city:?string,views:int,visitors:int,contacts:int}>
     */
    public function profiles(): array
    {
        $range = $this->range();
        $visitorIds = $this->googleVisitorIds();

        if ($visitorIds->isEmpty()) {
            return [];
        }

        $contactEvents = array_values(array_unique(array_merge(
            ProfileAnalyticsService::WEBSITE_CLICK_EVENTS,
            ProfileAnalyticsService::CONTACT_CLICK_EVENTS,
        )));

        $rows = $this->profileEventsForGoogleVisitors($visitorIds, $range)
            ->selectRaw(
                'profile_id,
                 sum(case when event_type = ? then 1 else 0 end) as views,
                 count(distinct visitor_id) as visitors,
                 sum(case when event_type in (' . implode(',', array_fill(0, count($contactEvents), '?')) . ') then 1 else 0 end) as contacts',
                array_merge([ProfileAnalyticsService::EVENT_PROFILE_VIEW], $contactEvents)
            )
            ->groupBy('profile_id')
            ->orderByDesc('views')
            ->limit(20)
            ->get();

        $profiles = Profile::query()
            ->whereIn('id', $rows->pluck('profile_id')->filter()->all())
            ->get(['id', 'name', 'slug', 'city'])
            ->keyBy('id');

        return $rows
            ->map(function ($row) use ($profiles): array {
                $profile = $profiles[$row->profile_id] ?? null;

                return [
                    'name' => (string) ($profile?->name ?? ('#' . $row->profile_id)),
                    'slug' => $profile?->slug,
                    'city' => $profile?->city,
                    'views' => (int) $row->views,
                    'visitors' => (int) $row->visitors,
                    'contacts' => (int) $row->contacts,
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array{label:string,count:int,percent:int}>
     */
    public function profileActions(): array
    {
        $range = $this->range();
        $visitorIds = $this->googleVisitorIds();

        if ($visitorIds->isEmpty()) {
            return [];
        }

        $rows = $this->profileEventsForGoogleVisitors($visitorIds, $range)
            ->where('event_type', '!=', ProfileAnalyticsService::EVENT_PROFILE_VIEW)
            ->selectRaw('event_type, count(*) as total')
            ->groupBy('event_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => (object) [
                'label' => $this->profileEventLabel((string) $row->event_type),
                'total' => (int) $row->total,
            ]);

        return $this->withPercent($rows, 'label', 'total');
    }

    private function siteEventsForGoogleVisitors(Collection $visitorIds, array $range): Builder
    {
        return SitePageEvent::query()
            ->whereBetween('created_at', [$range['start'], $range['end']])
            ->when($visitorIds->isEmpty(), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($visitorIds->isNotEmpty(), fn (Builder $query) => $query->whereIn('visitor_id', $visitorIds->all()));
    }

    private function profileEventsForGoogleVisitors(Collection $visitorIds, array $range): Builder
    {
        return ProfileEvent::query()
            ->whereBetween('created_at', [$range['start'], $range['end']])
            ->when($visitorIds->isEmpty(), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($visitorIds->isNotEmpty(), fn (Builder $query) => $query->whereIn('visitor_id', $visitorIds->all()));
    }

    private function googleSourceConstraint(): \Closure
    {
        return function (Builder $query): void {
            $query
                ->whereRaw('LOWER(COALESCE(source, "")) LIKE ?', ['%google%'])
                ->orWhereRaw('LOWER(COALESCE(utm_source, "")) LIKE ?', ['%google%'])
                ->orWhereRaw('LOWER(COALESCE(referrer, "")) LIKE ?', ['%google.%']);
        };
    }

    /**
     * @param  iterable<int, object>  $rows
     * @return array<int, array{label:string,count:int,percent:int}>
     */
    private function withPercent(iterable $rows, string $labelKey, string $countKey): array
    {
        $items = collect($rows)
            ->map(fn ($row) => [
                'label' => (string) ($row->{$labelKey} ?: '—'),
                'count' => (int) $row->{$countKey},
            ])
            ->values();
        $total = max(1, (int) $items->sum('count'));

        return $items
            ->map(fn (array $item) => [
                ...$item,
                'percent' => (int) round($item['count'] / $total * 100),
            ])
            ->all();
    }

    private function siteEventLabel(string $eventType, string $label): string
    {
        return match ($eventType) {
            'search_suggest_click' => 'Клік по підказці: ' . $label,
            'catalog_filter_apply' => 'Фільтр каталогу: ' . $label,
            'catalog_sort_change' => 'Сортування: ' . $label,
            'catalog_load_more' => 'Показати ще',
            'review_popup_open' => 'Відкрив форму відгуку',
            'review_submit_success' => 'Надіслав відгук',
            'cta_click' => 'CTA: ' . $label,
            default => $eventType . ': ' . $label,
        };
    }

    private function profileEventLabel(string $eventType): string
    {
        return match ($eventType) {
            'website_click' => 'Клік на сайт',
            'phone_click' => 'Клік на телефон',
            'email_click' => 'Клік на email',
            'telegram_click' => 'Клік на Telegram',
            'viber_click' => 'Клік на Viber',
            'whatsapp_click' => 'Клік на WhatsApp',
            'instagram_click' => 'Клік на Instagram',
            'facebook_click' => 'Клік на Facebook',
            'map_click' => 'Клік на карту',
            'info_tab_view' => 'Відкрив «Інформація»',
            'dossier_open' => 'Натиснув «Досьє»',
            'dossier_view' => 'Переглянув досьє',
            default => $eventType,
        };
    }
}
