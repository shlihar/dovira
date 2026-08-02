<?php

namespace App\Http\Controllers;

use App\Models\Profile;
use App\Models\ProfileFavorite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * «Обране»: гість тримає збережені профілі в localStorage, авторизований —
 * у БД (синхронізація і злиття локального списку при вході — favorites.js).
 */
class FavoritesController extends Controller
{
    public function index(Request $request): View
    {
        $profiles = [];

        if ($request->user() && Schema::hasTable('profile_favorites')) {
            $profiles = $this->mapProfiles(
                Profile::query()
                    ->whereIn('id', ProfileFavorite::query()
                        ->where('user_id', $request->user()->id)
                        ->latest('id')
                        ->pluck('profile_id'))
                    ->where('status', 'active')
                    ->with(['categories', 'services'])
                    ->withExists([
                        'claims as has_approved_claim' => fn ($claims) => $claims->where('status', 'approved'),
                    ])
                    ->get()
            );
        }

        return view('static.favorites', [
            'favoriteProfiles' => $profiles,
        ]);
    }

    /**
     * Гостьова сторінка: обмінює slugs із localStorage на готові картки.
     */
    public function resolve(Request $request): JsonResponse
    {
        $slugs = collect((array) $request->input('slugs', []))
            ->map(fn ($slug) => trim((string) $slug))
            ->filter()
            ->unique()
            ->take(100)
            ->values();

        if ($slugs->isEmpty()) {
            return response()->json(['html' => '', 'found' => []]);
        }

        $profiles = Profile::query()
            ->whereIn('slug', $slugs)
            ->where('status', 'active')
            ->with(['categories', 'services'])
            ->withExists([
                'claims as has_approved_claim' => fn ($claims) => $claims->where('status', 'approved'),
            ])
            ->get()
            // Порядок — як у списку користувача (останні збережені першими).
            ->sortBy(fn (Profile $profile) => $slugs->search($profile->slug))
            ->values();

        $mapped = $this->mapProfiles($profiles);

        return response()->json([
            'html' => view('static.partials.home-city-cards', ['profiles' => $mapped])->render(),
            'found' => collect($mapped)->pluck('slug')->values()->all(),
        ]);
    }

    public function toggle(Request $request, string $slug): JsonResponse
    {
        abort_unless(Schema::hasTable('profile_favorites'), 503);

        $profile = Profile::query()
            ->where('status', 'active')
            ->where('slug', $slug)
            ->firstOrFail();

        $existing = ProfileFavorite::query()
            ->where('user_id', $request->user()->id)
            ->where('profile_id', $profile->id)
            ->first();

        if ($existing) {
            $existing->delete();
            $saved = false;
        } else {
            ProfileFavorite::query()->firstOrCreate([
                'user_id' => $request->user()->id,
                'profile_id' => $profile->id,
            ]);
            $saved = true;
        }

        return response()->json([
            'saved' => $saved,
            'slugs' => $this->userSlugs($request),
        ]);
    }

    /**
     * Злиття гостьового списку (localStorage) після входу.
     */
    public function merge(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('profile_favorites'), 503);

        $slugs = collect((array) $request->input('slugs', []))
            ->map(fn ($slug) => trim((string) $slug))
            ->filter()
            ->unique()
            ->take(200)
            ->values();

        if ($slugs->isNotEmpty()) {
            Profile::query()
                ->whereIn('slug', $slugs)
                ->where('status', 'active')
                ->pluck('id')
                ->each(fn ($profileId) => ProfileFavorite::query()->firstOrCreate([
                    'user_id' => $request->user()->id,
                    'profile_id' => $profileId,
                ]));
        }

        return response()->json(['slugs' => $this->userSlugs($request)]);
    }

    public function slugs(Request $request): JsonResponse
    {
        return response()->json(['slugs' => $this->userSlugs($request)]);
    }

    /**
     * @return array<int, string>
     */
    private function userSlugs(Request $request): array
    {
        if (! Schema::hasTable('profile_favorites')) {
            return [];
        }

        return ProfileFavorite::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->with('profile:id,slug')
            ->get()
            ->pluck('profile.slug')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapProfiles($profiles): array
    {
        $catalog = app(CatalogPageController::class);

        return collect($profiles)
            ->map(fn (Profile $profile) => $catalog->mapCatalogProfileCard($profile))
            ->values()
            ->all();
    }
}
