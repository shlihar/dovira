<?php

namespace App\Http\Controllers;

use App\Models\Profile;
use App\Services\ProfileAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileEventController extends Controller
{
    public function __invoke(Request $request, string $slug, ProfileAnalyticsService $analytics): JsonResponse
    {
        $profile = Profile::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->firstOrFail();

        $validated = $request->validate([
            'event_type' => ['required', 'string', 'in:' . implode(',', ProfileAnalyticsService::ALLOWED_EVENT_TYPES)],
            'source' => ['nullable', 'string', 'max:120'],
            'internal_source' => ['nullable', 'string', 'max:120'],
            'referrer' => ['nullable', 'string', 'max:1000'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'utm_content' => ['nullable', 'string', 'max:255'],
            'utm_term' => ['nullable', 'string', 'max:255'],
            'target_url' => ['nullable', 'string', 'max:1000'],
            'device_type' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:120'],
        ]);

        $visitorId = $analytics->ensureVisitorId($request);
        $analytics->track($profile, [
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

