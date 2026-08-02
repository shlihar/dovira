<?php

namespace App\Services;

use App\Models\SitePageEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SitePageAnalyticsService
{
    public const EVENT_SITE_PAGE_VIEW = 'site_page_view';

    public const ALLOWED_EVENT_TYPES = [
        self::EVENT_SITE_PAGE_VIEW,
        // UI-дії: label несе значення (запит, фільтр, slug профілю, назва CTA).
        'search_query',
        // A/B hero головної: показ варіанта і цільові дії з нього
        // (label = 'variant' або 'variant:action').
        'hero_view',
        'hero_cta',
        'search_suggest_click',
        'catalog_filter_apply',
        'catalog_sort_change',
        'catalog_load_more',
        'review_popup_open',
        'review_submit_success',
        'review_submit_error',
        'cta_click',
    ];

    public function ensureVisitorId(Request $request): string
    {
        $cookie = (string) $request->cookie('dovira_visitor_id', '');

        return $cookie !== '' ? $cookie : (string) Str::uuid();
    }

    public function track(array $payload): SitePageEvent
    {
        $eventType = (string) ($payload['event_type'] ?? '');
        if (! in_array($eventType, self::ALLOWED_EVENT_TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported site page event type.');
        }

        $visitorId = isset($payload['visitor_id']) ? trim((string) $payload['visitor_id']) : null;
        $pagePath = $this->cleanNullable($payload['page_path'] ?? null);
        $eventLabel = $this->cleanNullable($payload['event_label'] ?? null);

        // Prevent accidental duplicate events from fast repeated init on a single
        // page render. Label входить у ключ дедуплікації, щоб різні пошукові
        // запити чи фільтри поспіль не склеювались в одну подію.
        $recentDuplicate = SitePageEvent::query()
            ->where('event_type', $eventType)
            ->when($visitorId, fn ($query) => $query->where('visitor_id', $visitorId))
            ->when($pagePath, fn ($query) => $query->where('page_path', $pagePath))
            ->where('event_label', $eventLabel)
            ->where('created_at', '>=', now()->subSeconds(10))
            ->latest('id')
            ->first();

        if ($recentDuplicate) {
            return $recentDuplicate;
        }

        return SitePageEvent::query()->create([
            'user_id' => isset($payload['user_id']) ? (int) $payload['user_id'] : null,
            'visitor_id' => $visitorId,
            'event_type' => $eventType,
            'event_label' => $eventLabel,
            'page_path' => $pagePath,
            'page_url' => $this->cleanNullable($payload['page_url'] ?? null),
            'source' => $this->cleanNullable($payload['source'] ?? null),
            'internal_source' => $this->cleanNullable($payload['internal_source'] ?? null),
            'referrer' => $this->cleanNullable($payload['referrer'] ?? null),
            'utm_source' => $this->cleanNullable($payload['utm_source'] ?? null),
            'utm_medium' => $this->cleanNullable($payload['utm_medium'] ?? null),
            'utm_campaign' => $this->cleanNullable($payload['utm_campaign'] ?? null),
            'utm_content' => $this->cleanNullable($payload['utm_content'] ?? null),
            'utm_term' => $this->cleanNullable($payload['utm_term'] ?? null),
            'device_type' => $this->cleanNullable($payload['device_type'] ?? null),
            'country' => $this->cleanNullable($payload['country'] ?? null),
            'city' => $this->cleanNullable($payload['city'] ?? null),
            'ip_hash' => $this->cleanNullable($payload['ip_hash'] ?? null),
            'user_agent' => $this->cleanNullable($payload['user_agent'] ?? null),
        ]);
    }

    private function cleanNullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}

