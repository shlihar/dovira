<?php

namespace App\Http\Controllers;

use App\Models\Profile;
use App\Services\ProfileAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Батч-прийом показів карток профілів (impressions). Фронт збирає видимі
 * картки через IntersectionObserver і шле пачкою — один запит на сторінку,
 * а не по запиту на картку.
 */
class ProfileImpressionController extends Controller
{
    public function __invoke(Request $request, ProfileAnalyticsService $analytics): JsonResponse
    {
        $validated = $request->validate([
            'slugs' => ['required', 'array', 'min:1', 'max:40'],
            'slugs.*' => ['string', 'max:255'],
            'promoted' => ['nullable', 'array', 'max:40'],
            'promoted.*' => ['string', 'max:255'],
            'context' => ['nullable', 'string', 'max:64'],
            'page_path' => ['nullable', 'string', 'max:255'],
            'device_type' => ['nullable', 'string', 'max:32'],
        ]);

        $slugs = array_values(array_unique($validated['slugs']));
        $profiles = Profile::query()
            ->whereIn('slug', $slugs)
            ->where('status', 'active')
            ->where('is_published', true)
            ->pluck('id', 'slug');

        // Покази на платних позиціях пишемо окремим контекстом: із них
        // складається цифра «скільки показів дала підписка» в кабінеті.
        $promotedSlugs = array_values(array_unique($validated['promoted'] ?? []));
        $promotedIds = $profiles->only($promotedSlugs)->values()->all();
        $regularIds = $profiles->except($promotedSlugs)->values()->all();

        $visitorId = $analytics->ensureVisitorId($request);

        $meta = [
            'visitor_id' => $visitorId,
            'user_id' => $request->user()?->id,
            'context' => $validated['context'] ?? null,
            'page_path' => $validated['page_path'] ?? null,
            'referrer' => mb_substr((string) $request->headers->get('referer', ''), 0, 255),
            'device_type' => $validated['device_type'] ?? null,
            'ip_hash' => hash('sha256', ($request->ip() ?? '0.0.0.0') . config('app.key')),
            'user_agent' => $request->userAgent(),
        ];

        $tracked = $analytics->trackImpressions($regularIds, $meta);
        if ($promotedIds !== []) {
            $tracked += $analytics->trackImpressions(
                $promotedIds,
                ['context' => ProfileAnalyticsService::CONTEXT_CATALOG_PROMOTED] + $meta
            );
        }

        return response()->json([
            'ok' => true,
            'tracked' => $tracked,
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
