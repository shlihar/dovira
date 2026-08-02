<?php

namespace App\Http\Controllers;

use App\Services\SitePageAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SitePageEventController extends Controller
{
    public function __invoke(Request $request, SitePageAnalyticsService $analytics): JsonResponse
    {
        $validated = $request->validate([
            'event_type' => ['required', 'string', 'in:' . implode(',', SitePageAnalyticsService::ALLOWED_EVENT_TYPES)],
            'event_label' => ['nullable', 'string', 'max:255'],
            'page_path' => ['nullable', 'string', 'max:255'],
            'page_url' => ['nullable', 'string', 'max:1000'],
            'source' => ['nullable', 'string', 'max:120'],
            'internal_source' => ['nullable', 'string', 'max:120'],
            'referrer' => ['nullable', 'string', 'max:1000'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'utm_content' => ['nullable', 'string', 'max:255'],
            'utm_term' => ['nullable', 'string', 'max:255'],
            'device_type' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:120'],
        ]);

        $visitorId = $analytics->ensureVisitorId($request);

        $analytics->track([
            ...$validated,
            'visitor_id' => $visitorId,
            'user_id' => $request->user()?->id,
            'referrer' => $validated['referrer'] ?? $request->headers->get('referer'),
            'ip_hash' => hash('sha256', ($request->ip() ?? '0.0.0.0') . config('app.key')),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'ok' => true,
            'visitor_id' => $visitorId,
        ])->cookie(
            'dovira_visitor_id',
            $visitorId,
            60 * 24 * 365,
            '/',
            null,
            $request->isSecure(),
            false,
            false,
            'Lax'
        );
    }
}

