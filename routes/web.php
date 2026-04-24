<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\LawyerController;
use App\Models\Region;
use App\Models\Lawyer;
use Illuminate\Support\Facades\Route;

Route::view('/', 'static.home')->name('home');

Route::get('/platform', function () {
    $regions = Region::orderBy('name')->get();
    $topLawyers = Lawyer::with('region')->orderBy('created_at', 'desc')->limit(4)->get();
    return view('static.platform', compact('regions', 'topLawyers'));
})->name('platform');

Route::view('/catalog', 'static.catalog')->name('catalog');
Route::get('/lawyer/{slug?}', function (?string $slug = null) {
    $profiles = config('static_profiles', []);

    if (empty($profiles)) {
        abort(404);
    }

    $defaultSlug = array_key_first($profiles);
    $slug = $slug ?? $defaultSlug;

    if (!isset($profiles[$slug])) {
        abort(404);
    }

    $profile = $profiles[$slug];
    $profile['slug'] = $slug;

    $categoryBySlug = [
        'nova-market' => 'retail',
        'resto-family' => 'retail',
        'freshcare-pharmacy' => 'retail',
        'tech-hub-store' => 'tech',
        'smarthome-store' => 'tech',
        'citydent-clinic' => 'health',
        'autocare-service' => 'auto',
        'green-delivery' => 'delivery',
        'quickbox-delivery' => 'delivery',
        'tutorspace-academy' => 'education',
        'buildcraft-studio' => 'construction',
        'bookflow' => 'services',
    ];

    $currentCategoryKey = $categoryBySlug[$slug] ?? null;

    $profilesCollection = collect($profiles)
        ->map(function (array $item, string $itemSlug) use ($categoryBySlug) {
            $item['slug'] = $itemSlug;
            $item['category_key'] = $categoryBySlug[$itemSlug] ?? null;
            return $item;
        })
        ->reject(fn (array $item) => $item['slug'] === $slug);

    $sameCategory = $profilesCollection
        ->filter(fn (array $item) => $currentCategoryKey !== null && $item['category_key'] === $currentCategoryKey)
        ->sortByDesc(fn (array $item) => ($item['rating'] ?? 0) * 1000 + ($item['reviews_count'] ?? 0))
        ->values();

    $fallbackProfiles = $profilesCollection
        ->reject(fn (array $item) => $item['category_key'] === $currentCategoryKey)
        ->sortByDesc(fn (array $item) => ($item['rating'] ?? 0) * 1000 + ($item['reviews_count'] ?? 0))
        ->values();

    $relatedProfiles = $sameCategory
        ->concat($fallbackProfiles)
        ->unique('slug')
        ->take(10)
        ->values()
        ->all();

    return view('static.lawyer', compact('profile', 'relatedProfiles'));
})->name('lawyer');
Route::view('/pro', 'static.pro')->name('pro');
Route::view('/faq', 'static.faq')->name('faq');

Route::prefix('app')->group(function () {
    Route::get('/catalog', [LawyerController::class, 'index'])->name('app.catalog');
    Route::get('/lawyers/{lawyer}', [LawyerController::class, 'show'])->name('app.lawyers.show');
    Route::post('/lawyers/{lawyer}/reviews', [LawyerController::class, 'storeReview'])
        ->middleware('auth')
        ->name('app.lawyers.reviews.store');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
