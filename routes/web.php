<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileClaimController;
use App\Http\Controllers\ProfileEventController;
use App\Http\Controllers\ProAccountController;
use App\Http\Controllers\PublicMediaController;
use App\Http\Controllers\LawyerController;
use App\Http\Controllers\CatalogPageController;
use App\Http\Controllers\HomePageController;
use App\Http\Controllers\OutreachController;
use App\Http\Controllers\SitePageEventController;
use App\Http\Controllers\SearchSuggestController;
use App\Models\Profile;
use App\Models\ProfileReview;
use App\Models\ProSubscription;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

// Real platform stats with a DB-less fallback: the site must stay up on
// hosting without a database, so failures degrade to zeros and the views
// hide stat blocks when the numbers are empty.
$publicPlatformStats = function (): array {
    return rescue(fn () => [
        'reviews' => (int) ProfileReview::query()->where('status', 'published')->count(),
        'pro_accounts' => (int) ProSubscription::query()->where('status', 'active')->count(),
        'profiles' => (int) Profile::query()
            ->where('status', 'active')
            ->where('is_published', true)
            ->where('show_in_catalog', true)
            ->count(),
    ], ['reviews' => 0, 'pro_accounts' => 0, 'profiles' => 0], false);
};

Route::get('/', HomePageController::class)->name('home');
// Внутрішній інструмент розсилки адвокатам (доступ лише персоналу — перевірка в контролері).
Route::get('/rozsylka', [OutreachController::class, 'index'])->name('outreach');
Route::get('/rozsylka/stats', [OutreachController::class, 'stats'])->name('outreach.stats');
Route::get('/sitemap.xml', [App\Http\Controllers\SeoController::class, 'sitemap'])->name('seo.sitemap');
Route::get('/robots.txt', [App\Http\Controllers\SeoController::class, 'robots'])->name('seo.robots');
Route::get('/llms.txt', [App\Http\Controllers\SeoController::class, 'llms'])->name('seo.llms');
// Машиночитаний прайс для AI-агентів (без рендера JS-сторінки /pro).
Route::get('/pricing.md', [App\Http\Controllers\SeoController::class, 'pricing'])->name('seo.pricing');
// Віджет рейтингу для сайтів власників: SVG-бейдж і iframe-версія.
Route::get('/widget/{slug}/badge.svg', [App\Http\Controllers\WidgetController::class, 'badge'])->name('widget.badge');
Route::get('/widget/{slug}/float.js', [App\Http\Controllers\WidgetController::class, 'float'])->name('widget.float');
Route::get('/widget/{slug}', [App\Http\Controllers\WidgetController::class, 'show'])->name('widget.show');
// On-the-fly resized WebP thumbnails — must be registered BEFORE the generic
// /media/{path} catch-all so it isn't swallowed by it.
Route::get('/media/thumb/{width}/{path}', \App\Http\Controllers\MediaThumbController::class)
    ->where(['width' => '[0-9]+', 'path' => '.*'])
    ->name('media.thumb');
Route::get('/media/{path}', PublicMediaController::class)
    ->where('path', '.*')
    ->name('media.public');

Route::get('/platform', function (App\Services\HomePageDataService $homePageData) use ($publicPlatformStats) {
    return view('static.platform', [
        'stats' => $publicPlatformStats(),
        'latestReviews' => rescue(fn () => array_slice($homePageData->latestReviews(), 0, 9), [], false),
    ]);
})->name('platform');

// Легкий per-IP ліміт на публічні read-сторінки: 60/хв людині не заважає
// (звичайний перегляд — одиниці запитів), але зрізає наївні скрейпери, що
// хочуть зняти весь каталог одним потоком. Основний фільтр ботів — на edge
// (Cloudflare); це backstop на рівні застосунку.
Route::get('/catalog', [CatalogPageController::class, 'index'])
    ->middleware('throttle:60,1')
    ->name('catalog');
// SEO-посадкові «категорія» і «категорія × місто» на ЧПУ-адресах —
// під запити штибу «стоматології київ відгуки». Slug-констрейнти, щоб
// не перехоплювати службові шляхи.
Route::get('/catalog/{category}', [CatalogPageController::class, 'landing'])
    ->where('category', '[a-z0-9\-]+')
    ->middleware('throttle:60,1')
    ->name('catalog.landing');
Route::get('/catalog/{category}/{city}', [CatalogPageController::class, 'landing'])
    ->where(['category' => '[a-z0-9\-]+', 'city' => '[a-z0-9\-]+'])
    ->middleware('throttle:60,1')
    ->name('catalog.landing.city');
Route::get('/search/suggest', SearchSuggestController::class)->name('search.suggest');

// «Обране»: сторінка і резолв гостьового списку — публічні,
// синхронізація — лише для авторизованих.
Route::get('/favorites', [\App\Http\Controllers\FavoritesController::class, 'index'])->name('favorites.index');
Route::post('/favorites/resolve', [\App\Http\Controllers\FavoritesController::class, 'resolve'])->name('favorites.resolve');
Route::middleware('auth')->group(function (): void {
    Route::post('/favorites/{slug}/toggle', [\App\Http\Controllers\FavoritesController::class, 'toggle'])->name('favorites.toggle');
    Route::post('/favorites/merge', [\App\Http\Controllers\FavoritesController::class, 'merge'])->name('favorites.merge');
    Route::get('/favorites/slugs', [\App\Http\Controllers\FavoritesController::class, 'slugs'])->name('favorites.slugs');
});
Route::get('/geo/home-sections', [\App\Http\Controllers\GeoController::class, 'homeSections'])
    ->middleware('throttle:30,1')
    ->name('geo.home-sections');
// Два шари: сплеск (12/хв) і погодинна квота (30/год з одного IP),
// щоб не заливали чергу модерації повільним рівним потоком.
Route::post('/reviews', [CatalogPageController::class, 'storeReviewByProfile'])
    ->middleware(['throttle:12,1', 'throttle:30,60'])
    ->name('reviews.store');
Route::post('/profiles/{slug}/reviews', [CatalogPageController::class, 'storeReview'])
    ->middleware(['throttle:12,1', 'throttle:30,60'])
    ->name('profile.reviews.store');
// Пагінація відгуків — окремий AJAX-ендпоінт, який скрейпер міг би
// смикати десятками сторінок на профіль. Суворіший ліміт, ніж на самій
// сторінці профілю: два шари — сплеск (30/хв) і погодинна квота (300/год).
Route::get('/profiles/{slug}/reviews', [CatalogPageController::class, 'loadProfileReviews'])
    ->middleware(['throttle:30,1', 'throttle:300,60'])
    ->name('profile.reviews.load');
// Заявка «передзвоніть мені» з профілю (лід у кабінет). Антиспам: throttle + honeypot.
Route::post('/profiles/{slug}/lead', [\App\Http\Controllers\ProfileLeadController::class, 'store'])
    ->middleware(['throttle:6,1', 'throttle:20,60'])
    ->name('profile.lead.store');
Route::post('/profiles/{slug}/events', ProfileEventController::class)
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:120,1')
    ->name('profile.events.store');
Route::post('/events/site-page-view', SitePageEventController::class)
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:120,1')
    ->name('site.events.page-view');
// UI-дії (пошук, фільтри, воронка відгуків, CTA) — той самий контролер,
// окремий шлях для читабельності клієнтського коду.
Route::post('/events/ui', SitePageEventController::class)
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:120,1')
    ->name('site.events.ui');
// Коротке посилання «залишити відгук»: бізнес шле його клієнту або показує
// QR-кодом. Веде на профіль з автовідкриттям форми відгуку (?review=1).
// Відписка бізнесу від листів-тригерів (signed-URL з листа, без авторизації).
Route::get('/review-alerts/unsubscribe/{profile}', [ProAccountController::class, 'unsubscribeOutreach'])
    ->middleware('signed')
    ->name('review-outreach.unsubscribe');

Route::get('/r/{slug}', function (string $slug) {
    return redirect()->to(route('profile.show', ['slug' => $slug]).'?review=1&utm_source=dovira_invite#reviews');
})->where('slug', '[a-z0-9\-]+')->name('profile.review-link');

Route::get('/profiles/{slug?}', [CatalogPageController::class, 'showProfile'])
    ->middleware('throttle:60,1')
    ->name('profile.show');
Route::get('/lawyer/{slug?}', function (?string $slug = null) {
    return redirect()->route('profile.show', array_merge(
        $slug ? ['slug' => $slug] : [],
        request()->query()
    ));
})->name('lawyer');

Route::get('/pro', function () use ($publicPlatformStats) {
    return view('static.pro', [
        'stats' => $publicPlatformStats(),
    ]);
})->name('pro');
Route::view('/faq', 'static.faq')->name('faq');
// Контентні сторінки авторитетності: віддаємо реальні цифри платформи —
// конкретна статистика підсилює цитування (Princeton GEO: +37%).
Route::get('/chomu-dovirati', fn () => view('static.trust', ['stats' => $publicPlatformStats()]))->name('trust');
Route::get('/yak-formuyetsya-reyting', fn () => view('static.rating-method', ['stats' => $publicPlatformStats()]))->name('rating.method');
Route::view('/privacy', 'static.privacy')->name('privacy');
Route::view('/terms', 'static.terms')->name('terms');

// Оплата PRO через monopay / monobank acquiring.
Route::post('/webhooks/monopay', [App\Http\Controllers\PaymentWebhookController::class, 'monopay'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('webhooks.monopay');
Route::get('/blog', [App\Http\Controllers\BlogController::class, 'index'])->name('blog');
Route::get('/blog/{slug}', [App\Http\Controllers\BlogController::class, 'show'])->name('blog.show');

Route::prefix('app')->group(function () {
    Route::get('/catalog', [LawyerController::class, 'index'])->name('app.catalog');
    Route::get('/lawyers/{lawyer}', [LawyerController::class, 'show'])->name('app.lawyers.show');
    Route::post('/lawyers/{lawyer}/reviews', [LawyerController::class, 'storeReview'])
        ->middleware('auth')
        ->name('app.lawyers.reviews.store');
});

// Breeze leftover: the old Tailwind dashboard view is not part of the product.
// Verification flows redirect here by name, so keep the route and forward it
// to the real user cabinet.
Route::get('/dashboard', function () {
    return redirect()->route('profile.edit');
})->middleware(['auth'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::post('/profiles/{slug}/reviews/{review}/replies', [CatalogPageController::class, 'storeReviewReply'])->name('profile.reviews.replies.store');
    Route::post('/profiles/{slug}/reviews/{review}/replies/{reply}/reaction', [CatalogPageController::class, 'toggleReviewReplyReaction'])->name('profile.reviews.replies.reactions.toggle');
    Route::post('/profiles/{slug}/reviews/{review}/official-reply/reaction', [CatalogPageController::class, 'toggleOfficialReplyReaction'])->name('profile.reviews.official-reply.reactions.toggle');
    Route::post('/profiles/{slug}/reviews/{review}/reaction', [CatalogPageController::class, 'toggleReviewReaction'])->name('profile.reviews.reactions.toggle');
    Route::post('/profiles/{slug}/reviews/{review}/reports', [CatalogPageController::class, 'storeReviewReport'])->name('profile.reviews.reports.store');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::patch('/profile/reviews/{review}', [ProfileController::class, 'updateReview'])->name('profile.reviews.update');
    Route::delete('/profile/reviews/{review}', [ProfileController::class, 'withdrawReview'])->name('profile.reviews.withdraw');
    Route::get('/pro/account', ProAccountController::class)->name('pro.account');
    Route::get('/pro/account/analytics', [ProAccountController::class, 'analytics'])->name('pro.account.analytics');
    // Дії з високою довірою (привʼязка/створення профілю, оплата PRO) вимагають
    // підтвердженого email — інакше keby-нік із чужою поштою міг би заявити права.
    Route::post('/pro/account/claims', [ProfileClaimController::class, 'store'])->middleware('verified')->name('pro.account.claims.store');
    // OTP-підтвердження прав на профіль (код на email/телефон із профілю).
    Route::post('/pro/account/claims/request-code', [ProfileClaimController::class, 'requestCode'])
        ->middleware(['verified', 'throttle:12,1'])->name('pro.account.claims.request-code');
    Route::post('/pro/account/claims/verify-code', [ProfileClaimController::class, 'verifyCode'])
        ->middleware(['verified', 'throttle:20,1'])->name('pro.account.claims.verify-code');
    Route::post('/pro/account/profiles', [ProfileClaimController::class, 'createProfile'])->middleware('verified')->name('pro.account.profiles.store');
    Route::patch('/pro/account/profiles/{profile}', [ProAccountController::class, 'updateProfile'])->name('pro.account.profiles.update');
    Route::get('/pro/account/billing/{profile}/pay', [ProAccountController::class, 'showBillingPayment'])->middleware('verified')->name('pro.account.billing.pay');
    Route::post('/pro/account/billing/{profile}/checkout', [ProAccountController::class, 'purchaseSubscription'])->middleware('verified')->name('pro.account.billing.checkout');
    Route::post('/pro/account/billing/{profile}/pay/monopay', [ProAccountController::class, 'startBillingMonopay'])->middleware(['verified', 'throttle:10,1'])->name('pro.account.billing.pay.monopay');
    Route::get('/pro/account/billing/success/{token}', [ProAccountController::class, 'billingPaymentSuccess'])->middleware('verified')->name('pro.account.billing.success');
    Route::post('/pro/account/billing/{profile}/cancel', [ProAccountController::class, 'cancelTestSubscription'])->name('pro.account.billing.cancel');
    Route::get('/pro/account/billing/payments/{payment}/receipt', [ProAccountController::class, 'downloadBillingReceipt'])->name('pro.account.billing.receipt');
    Route::get('/pro/account/reviews/{review}/detail', [ProAccountController::class, 'reviewDetail'])->name('pro.account.reviews.detail');
    Route::post('/pro/account/reviews/{review}/report', [ProAccountController::class, 'reportReview'])->name('pro.account.reviews.report');
    Route::post('/pro/account/reviews/{review}/reply', [ProAccountController::class, 'upsertReply'])->name('pro.account.reviews.reply');
    Route::patch('/pro/account/reviews/{review}/visibility', [ProAccountController::class, 'updateReviewVisibility'])->name('pro.account.reviews.visibility');
    Route::patch('/pro/account/notifications/{profile}/preferences', [ProAccountController::class, 'updateNotificationPreferences'])->name('pro.account.notifications.preferences');
    Route::post('/pro/account/notifications/{profile}/read-all', [ProAccountController::class, 'markNotificationsRead'])->name('pro.account.notifications.read-all');
});

require __DIR__.'/auth.php';
