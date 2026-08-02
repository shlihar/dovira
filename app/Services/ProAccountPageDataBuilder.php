<?php

namespace App\Services;

use App\Models\Category;
use App\Models\CategoryService;
use App\Models\Profile;
use App\Models\ProfileClaim;
use App\Models\ProfileEvent;
use App\Models\PlatformNotification;
use App\Models\ProfileReview;
use App\Models\ProSubscription;
use App\Models\Region;
use App\Models\User;
use App\Support\MediaUrl;
use App\Support\RegionCityDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ProAccountPageDataBuilder
{
    public function __construct(
        private readonly ProfileAnalyticsService $analyticsService,
        private readonly ProProfileNotificationService $notificationService,
    ) {
    }

    /**
     * @param  array{
     *   tab?:string,
     *   selectedProfileId?:int|null,
     *   analyticsPeriod?:string,
     *   claimSearch?:string,
     *   selectedClaimProfileId?:int|null
     * }  $state
     * @return array<string, mixed>
     */
    public function build(User $user, array $state = []): array
    {
        $tab = $this->normalizeTab((string) ($state['tab'] ?? 'overview'));
        $analyticsPeriod = $this->normalizeAnalyticsPeriod((string) ($state['analyticsPeriod'] ?? 'last_30'));
        $selectedProfileId = max(0, (int) ($state['selectedProfileId'] ?? 0));
        $claimSearch = trim((string) ($state['claimSearch'] ?? ''));
        $selectedClaimProfileId = max(0, (int) ($state['selectedClaimProfileId'] ?? 0));
        $analyticsPeriodOptions = $this->analyticsPeriodOptions();

        $ownedProfiles = $user->ownedProfiles()
            ->with([
                'region:id,name',
                'categories' => fn ($query) => $query->orderByDesc('profile_category.is_primary')->orderBy('name'),
                'services:id,name',
            ])
            ->withExists([
                'claims as has_approved_claim' => fn ($claims) => $claims->where('status', 'approved'),
            ])
            ->orderByDesc('is_pro')
            ->orderBy('name')
            ->get();

        $currentProfile = $selectedProfileId > 0
            ? $ownedProfiles->firstWhere('id', $selectedProfileId)
            : $ownedProfiles->first();

        if (! $currentProfile && $tab !== 'claims') {
            $tab = 'claims';
        }

        $shouldLoadClaimsWorkspace = true;
        $shouldLoadProfileWorkspace = $currentProfile instanceof Profile;

        $selectedClaimProfile = null;
        $claimSearchResults = collect();
        $userClaims = collect();
        $categories = collect();
        $categoryChildren = [];
        $categoryServices = collect();
        $regions = collect();
        $regionCityDirectory = [];

        if ($shouldLoadClaimsWorkspace || $shouldLoadProfileWorkspace) {
            $categories = Category::query()
                ->whereNull('parent_id')
                ->where('is_active', true)
                ->with([
                    'children' => fn ($children) => $children
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->orderBy('name'),
                ])
                ->orderBy('name')
                ->get(['id', 'name']);

            $categoryChildren = $categories
                ->mapWithKeys(fn (Category $category) => [
                    (int) $category->id => $category->children
                        ->map(fn (Category $child) => [
                            'id' => (int) $child->id,
                            'name' => $child->name,
                        ])
                        ->values()
                        ->all(),
                ])
                ->all();

            $categoryServices = CategoryService::query()
                ->where('is_active', true)
                ->orderBy('category_id')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'category_id', 'name']);

            $regions = Region::query()
                ->orderBy('name')
                ->get(['id', 'name']);

            $regionCityDirectory = RegionCityDirectory::payload($regions);
        }

        if ($shouldLoadClaimsWorkspace) {
            $userClaims = ProfileClaim::query()
                ->with(['profile:id,name,slug,city,status,owner_user_id', 'reviewer:id,name'])
                ->where('user_id', $user->id)
                ->latest('updated_at')
                ->get();

            if ($selectedClaimProfileId > 0) {
                $selectedClaimProfile = Profile::query()
                    ->with(['region:id,name', 'categories' => fn ($query) => $query->orderByDesc('profile_category.is_primary')->orderBy('name')])
                    ->withExists([
                        'claims as has_approved_claim' => fn ($claims) => $claims->where('status', 'approved'),
                    ])
                    ->where('status', 'active')
                    ->find($selectedClaimProfileId);
            }

            if ($claimSearch !== '') {
                $claimSearchResults = Profile::query()
                    ->with(['region:id,name', 'categories' => fn ($query) => $query->orderByDesc('profile_category.is_primary')->orderBy('name')])
                    ->withExists([
                        'claims as has_approved_claim' => fn ($claims) => $claims->where('status', 'approved'),
                    ])
                    ->where('status', 'active')
                    ->where(function ($query) use ($claimSearch): void {
                        $term = '%' . $claimSearch . '%';
                        $query->where('name', 'like', $term)
                            ->orWhere('slug', 'like', $term)
                            ->orWhere('city', 'like', $term)
                            ->orWhere('phone', 'like', $term)
                            ->orWhere('website', 'like', $term);
                    })
                    ->orderByDesc('is_pro')
                    ->orderByDesc('is_verified')
                    ->orderByDesc('reviews_count')
                    ->limit(8)
                    ->get();
            }
        }

        $latestSubscription = null;
        $latestClaim = null;
        $reviews = null;
        $analytics = null;
        $actionItems = [];
        $analyticsActionRows = [];
        $billingSummary = [];
        $reviewsRatingTimeline = [
            'labels' => [],
            'review_counts' => [],
            'rating_values' => [],
        ];
        $overviewStats = [];
        $sources = [];
        $deviceTypes = [];
        $reviewStatusSummary = [];
        $publishedReviewsCount = 0;
        $answeredReviewsCount = 0;
        $replyRate = 0;
        $completionPercent = null;
        $latestReviewsForOverview = [];
        $canManageReviewModeration = false;
        $reviewsMetric = [
            'current' => 0,
            'previous' => 0,
            'diff_percent' => 0.0,
            'is_up' => true,
        ];
        $overviewTrustMeta = [
            'popularity_rank' => null,
            'popularity_total' => 0,
            'popularity_rank_label' => 'Популярність: —',
            'popularity_rank_title' => 'Немає достатньо даних для розрахунку популярності',
            'popularity_rank_color' => '#9fb0cf',
            'trust_color_accent' => '#34c77a',
            'trust_color_soft' => '#eaf9f1',
            'trust_tone' => 'excellent',
        ];
        $notificationPreferences = $this->notificationService->defaults();
        $notificationUnreadCount = 0;
        $profileNotifications = collect();
        $notificationGroups = $this->notificationGroups();
        $trendDisplays = [
            'views' => $this->formatTrendDisplay(['current' => 0, 'previous' => 0, 'diff_percent' => 0.0, 'is_up' => true], 'За 30 днів'),
            'website_clicks' => $this->formatTrendDisplay(['current' => 0, 'previous' => 0, 'diff_percent' => 0.0, 'is_up' => true], 'За 30 днів'),
            'contact_clicks' => $this->formatTrendDisplay(['current' => 0, 'previous' => 0, 'diff_percent' => 0.0, 'is_up' => true], 'За 30 днів'),
            'reviews' => $this->formatTrendDisplay(['current' => 0, 'previous' => 0, 'diff_percent' => 0.0, 'is_up' => true], 'За 30 днів'),
        ];

        if ($currentProfile instanceof Profile) {
            $latestSubscription = $currentProfile->proSubscriptions()
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->latest('started_at')
                ->latest('id')
                ->first();

            $latestClaim = $currentProfile->claims()
                ->latest('created_at')
                ->first();

            $completionPercent = $currentProfile->completenessPercent();
            $canManageReviewModeration = $currentProfile->hasActiveProSubscription();
            if ($tab === 'analytics' && ! $canManageReviewModeration) {
                $tab = 'billing';
            }
            $overviewTrustMeta = [
                ...$this->buildTrustMeta($currentProfile),
                ...$this->buildTrustVisualMeta($currentProfile),
            ];
            $notificationPreferences = $this->notificationService->resolve($currentProfile);
            $notificationUnreadCount = $this->notificationService->unreadCount($currentProfile);
            $profileNotifications = $this->notificationService
                ->notificationsForProfile($currentProfile)
                ->map(fn (PlatformNotification $notification) => $this->mapProfileNotification($notification))
                ->filter()
                ->values();

            // Список відгуків (найважча панель, сотні КБ) рендеримо лише на
            // вкладці відгуків — інші панелі легкі й лишаються в DOM для
            // миттєвого перемикання.
            $shouldLoadReviewSummary = true;
            $shouldLoadOverviewData = true;
            $shouldLoadReviewsWorkspace = $tab === 'reviews';

            if ($shouldLoadReviewSummary) {
                $publishedReviewsCount = (int) $currentProfile->reviews()->where('status', 'published')->count();
                // «Без відповіді» та рівень відповідей рахуємо лише по відгуках,
                // залишених на платформі: імпортовані (з Google тощо) не є
                // «боргом» власника і не мають тиснути лічильником.
                $nativePublishedCount = (int) $currentProfile->reviews()
                    ->where('status', 'published')
                    ->whereNull('external_source_type')
                    ->count();
                $answeredReviewsCount = (int) $currentProfile->reviews()
                    ->where('status', 'published')
                    ->whereNull('external_source_type')
                    ->whereHas('officialReply')
                    ->count();
                $replyRate = $nativePublishedCount > 0
                    ? (int) round(($answeredReviewsCount / $nativePublishedCount) * 100)
                    : 0;

                $reviewStatusSummary = [
                    'total' => (int) $currentProfile->reviews()->count(),
                    'pending' => (int) $currentProfile->reviews()->whereIn('status', ['pending', 'under_review'])->count(),
                    'published' => $publishedReviewsCount,
                    'answered' => $answeredReviewsCount,
                    'without_reply' => (int) $currentProfile->reviews()
                        ->where('status', 'published')
                        ->whereNull('external_source_type')
                        ->doesntHave('officialReply')
                        ->count(),
                    'negative' => (int) $currentProfile->reviews()
                        ->where('status', 'published')
                        ->where('rating', '<=', 2)
                        ->count(),
                    'positive' => (int) $currentProfile->reviews()
                        ->where('status', 'published')
                        ->where('rating', '>=', 4)
                        ->count(),
                    'hidden' => (int) $currentProfile->reviews()->where('status', 'hidden')->count(),
                ];
            } elseif ($tab !== 'profile') {
                $reviewStatusSummary = [
                    'without_reply' => (int) $currentProfile->reviews()
                        ->where('status', 'published')
                        ->whereNull('external_source_type')
                        ->doesntHave('officialReply')
                        ->count(),
                ];
            }

            if ($shouldLoadReviewsWorkspace) {
                $reviews = ProfileReview::query()
                    ->with(['author:id,name,avatar_url', 'officialReply.author:id,name'])
                    ->where('profile_id', $currentProfile->id)
                    ->orderByRaw('CASE WHEN external_review_date IS NULL THEN 1 ELSE 0 END')
                    ->orderByDesc('external_review_date')
                    ->orderByDesc('published_at')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    // Bounds the page payload for very large histories; the
                    // list itself is revealed client-side in pages of 15.
                    ->limit(200)
                    ->get();
            }

            if ($shouldLoadOverviewData) {
                $billingSummary = $this->buildBillingSummary($currentProfile, $latestSubscription);
                $leadsData = $this->buildLeadsData($currentProfile, (bool) ($billingSummary['is_active'] ?? false));

                $latestReviewsForOverview = $this->mapPublicProfileReviews(
                    $currentProfile,
                    ProfileReview::query()
                        ->with(['author:id,name,avatar_url', 'officialReply.author:id,name'])
                        ->where('profile_id', $currentProfile->id)
                        ->where('status', 'published')
                        ->orderByDesc('published_at')
                        ->orderByDesc('created_at')
                        ->limit(3)
                        ->get()
                );

                if ($canManageReviewModeration) {
                    $analytics = $this->analyticsService->analyticsSummary($currentProfile, $analyticsPeriod);
                    $reviewsMetric = $this->buildPublishedReviewsMetric(
                        $currentProfile,
                        data_get($analytics, 'period.from'),
                        data_get($analytics, 'period.to')
                    );
                    $trendDisplays = [
                        'views' => $this->formatTrendDisplay((array) data_get($analytics, 'metrics.views', []), (string) data_get($analytics, 'period.short_label', 'За 30 днів')),
                        'website_clicks' => $this->formatTrendDisplay((array) data_get($analytics, 'metrics.website_clicks', []), (string) data_get($analytics, 'period.short_label', 'За 30 днів')),
                        'contact_clicks' => $this->formatTrendDisplay((array) data_get($analytics, 'metrics.contact_clicks', []), (string) data_get($analytics, 'period.short_label', 'За 30 днів')),
                        'reviews' => $this->formatTrendDisplay($reviewsMetric, (string) data_get($analytics, 'period.short_label', 'За 30 днів')),
                    ];

                    $overviewStats = [
                        [
                            'value' => number_format((int) ($analytics['views'] ?? 0), 0, '.', ' '),
                            'label' => 'переглядів ' . mb_strtolower((string) data_get($analytics, 'period.short_label', 'за 30 днів')),
                        ],
                        [
                            'value' => number_format((int) ($analytics['unique'] ?? 0), 0, '.', ' '),
                            'label' => 'унікальних відвідувачів',
                        ],
                        [
                            'value' => number_format((int) ($analytics['contact_clicks'] ?? 0), 0, '.', ' '),
                            'label' => 'кліків на контакти',
                        ],
                        [
                            'value' => rtrim(rtrim(number_format((float) ($analytics['ctr'] ?? 0), 2, '.', ''), '0'), '.') . '%',
                            'label' => 'CTR профілю',
                        ],
                    ];

                    $sources = $this->buildSourceSummary($analytics['sources_timeline']['series'] ?? []);
                    $deviceTypes = $this->normalizeDeviceTypes($analytics['device_types'] ?? []);
                    $analyticsActionRows = $this->buildAnalyticsActionRows(
                        $currentProfile,
                        data_get($analytics, 'period.from'),
                        data_get($analytics, 'period.to'),
                        (string) data_get($analytics, 'period.short_label', 'За 30 днів')
                    );
                    $reviewsRatingTimeline = $this->buildReviewsRatingTimeline(
                        $currentProfile,
                        data_get($analytics, 'period.from'),
                        data_get($analytics, 'period.to')
                    );
                }

                $actionItems = $this->buildActionItems($currentProfile, $latestSubscription, $latestClaim, $reviewStatusSummary, $completionPercent, $analytics);
            }
        }

        return [
            'user' => $user,
            'leadsData' => $leadsData ?? ['available' => false, 'total' => 0, 'unread' => 0, 'items' => [], 'is_pro' => false],
            'tab' => $tab,
            'ownedProfiles' => $ownedProfiles,
            'currentProfile' => $currentProfile,
            'latestSubscription' => $latestSubscription,
            'latestClaim' => $latestClaim,
            'reviews' => $reviews,
            'analytics' => $analytics,
            'actionItems' => $actionItems,
            'analyticsActionRows' => $analyticsActionRows,
            'billingSummary' => $billingSummary,
            'reviewsRatingTimeline' => $reviewsRatingTimeline,
            'overviewStats' => $overviewStats,
            'sources' => $sources,
            'deviceTypes' => $deviceTypes,
            'reviewStatusSummary' => $reviewStatusSummary,
            'publishedReviewsCount' => $publishedReviewsCount,
            'answeredReviewsCount' => $answeredReviewsCount,
            'replyRate' => $replyRate,
            'completionPercent' => $completionPercent,
            'latestReviewsForOverview' => $latestReviewsForOverview,
            'claimSearch' => $claimSearch,
            'claimSearchResults' => $claimSearchResults,
            'selectedClaimProfile' => $selectedClaimProfile,
            'userClaims' => $userClaims,
            'categories' => $categories,
            'categoryChildren' => $categoryChildren,
            'categoryServices' => $categoryServices,
            'regions' => $regions,
            'analyticsPeriod' => $analyticsPeriod,
            'analyticsPeriodOptions' => $analyticsPeriodOptions,
            'reviewsMetric' => $reviewsMetric,
            'trendDisplays' => $trendDisplays,
            'canManageReviewModeration' => $canManageReviewModeration,
            'overviewTrustMeta' => $overviewTrustMeta,
            'notificationPreferences' => $notificationPreferences,
            'notificationUnreadCount' => $notificationUnreadCount,
            'profileNotifications' => $profileNotifications,
            'notificationGroups' => $notificationGroups,
            'regionCityDirectory' => $regionCityDirectory,
        ];
    }

    /**
     * @return array{
     *   popularity_rank:int|null,
     *   popularity_total:int,
     *   popularity_rank_label:string,
     *   popularity_rank_title:string,
     *   popularity_rank_color:string
     * }
     */
    private function buildTrustMeta(Profile $profile): array
    {
        $popularityRankLabel = 'Популярність: —';
        $popularityRankTitle = 'Немає підкатегорії або міста для розрахунку популярності';
        $popularityRankColor = '#9fb0cf';

        $popularityContext = $this->analyticsService->popularityRankInLocalContext($profile);
        $rank = $popularityContext['rank'];
        $total = (int) ($popularityContext['total'] ?? 0);
        $city = (string) ($popularityContext['city'] ?? '');
        $contextType = (string) ($popularityContext['context_type'] ?? '');
        $contextName = (string) ($popularityContext['context_name'] ?? '');

        if ($rank !== null && $total > 0 && $contextName !== '' && $city !== '') {
            $contextLabel = $contextType === 'subcategory' ? 'підкатегорії' : 'категорії';
            $popularityRankLabel = "Популярність: {$contextName}, {$city} · #{$rank} із {$total}";
            $popularityRankTitle = "Позиція #{$rank} серед {$total} профілів у {$contextLabel} «{$contextName}» в місті {$city}";

            $relative = $rank / $total;
            $popularityRankColor = match (true) {
                $relative <= 0.20 => '#30ba73',
                $relative <= 0.60 => '#f7a21f',
                default => '#e74b4b',
            };
        }

        return [
            'popularity_rank' => $rank !== null ? (int) $rank : null,
            'popularity_total' => $total,
            'popularity_rank_label' => $popularityRankLabel,
            'popularity_rank_title' => $popularityRankTitle,
            'popularity_rank_color' => $popularityRankColor,
        ];
    }

    /**
     * @return array{
     *   trust_color_accent:string,
     *   trust_color_soft:string,
     *   trust_tone:string
     * }
     */
    private function buildTrustVisualMeta(Profile $profile): array
    {
        $rating = max(0.0, min(5.0, (float) ($profile->rating_avg ?? 0)));

        $tone = match (true) {
            $rating >= 4.0 => 'excellent',
            $rating >= 2.5 => 'warning',
            default => 'danger',
        };

        [$accentColor, $softColor] = match ($tone) {
            'excellent' => ['#34c77a', '#eaf9f1'],
            'warning' => ['#f7a21f', '#fff4e8'],
            default => ['#e74b4b', '#fdecec'],
        };

        return [
            'trust_color_accent' => $accentColor,
            'trust_color_soft' => $softColor,
            'trust_tone' => $tone,
        ];
    }

    private function normalizeTab(string $tab): string
    {
        return in_array($tab, ['overview', 'profile', 'reviews', 'leads', 'analytics', 'notifications', 'billing', 'claims'], true)
            ? $tab
            : 'overview';
    }

    /**
     * @return array<int, array{key:string,title:string,description:string,items:array<int, array{key:string,label:string,description:string}>}>
     */
    private function notificationGroups(): array
    {
        return [
            [
                'key' => 'channels',
                'title' => 'Канали доставки',
                'description' => 'Керуйте тим, як отримувати важливі події по цьому профілю.',
                'items' => [
                    ['key' => 'in_app_enabled', 'label' => 'У кабінеті', 'description' => 'Показувати сповіщення всередині PRO-кабінету.'],
                    ['key' => 'email_enabled', 'label' => 'Email', 'description' => 'Надсилати ключові події на email власника акаунта.'],
                    ['key' => 'digest_weekly_enabled', 'label' => 'Щотижневий дайджест', 'description' => 'Зводка по аналітиці, відгуках і статусу профілю.'],
                ],
            ],
            [
                'key' => 'reviews',
                'title' => 'Відгуки та репутація',
                'description' => 'Найоперативніші сигнали для роботи з відгуками.',
                'items' => [
                    ['key' => 'review_new_enabled', 'label' => 'Новий відгук', 'description' => 'Коли по профілю залишили новий відгук.'],
                    ['key' => 'review_negative_enabled', 'label' => 'Негативний відгук', 'description' => 'Окремо сповіщати про 1-2 зірки.'],
                    ['key' => 'review_unanswered_enabled', 'label' => 'Без відповіді', 'description' => 'Нагадування про відгуки, на які ще не відповіли.'],
                    ['key' => 'lead_new_enabled', 'label' => 'Нова заявка', 'description' => 'Email-лист, коли клієнт залишив заявку з профілю.'],
                ],
            ],
            [
                'key' => 'profile',
                'title' => 'Профіль',
                'description' => 'Видимість профілю та критичні зміни по сторінці.',
                'items' => [
                    ['key' => 'profile_status_enabled', 'label' => 'Статус публікації', 'description' => 'Коли профіль опубліковано або прибрано з каталогу.'],
                    ['key' => 'profile_completeness_enabled', 'label' => 'Заповнення профілю', 'description' => 'Нагадування про критично незаповнені дані.'],
                ],
            ],
            [
                'key' => 'analytics',
                'title' => 'Аналітика',
                'description' => 'Сигнали по трафіку та конверсії без зайвого шуму.',
                'items' => [
                    ['key' => 'analytics_drop_enabled', 'label' => 'Падіння трафіку', 'description' => 'Коли перегляди суттєво знизились проти попереднього періоду.'],
                    ['key' => 'analytics_zero_conversion_enabled', 'label' => 'Немає конверсії', 'description' => 'Коли є трафік, але немає кліків на контакти.'],
                    ['key' => 'analytics_digest_enabled', 'label' => 'Звіт по аналітиці', 'description' => 'Періодичний дайджест з KPI цього профілю.'],
                ],
            ],
            [
                'key' => 'billing',
                'title' => 'Оплата',
                'description' => 'Критичні події по підписці мають доходити без затримки.',
                'items' => [
                    ['key' => 'billing_payment_success_enabled', 'label' => 'Успішна оплата', 'description' => 'Підтвердження активації або продовження підписки.'],
                    ['key' => 'billing_payment_failed_enabled', 'label' => 'Помилка оплати', 'description' => 'Коли платіж не пройшов і потрібна дія.'],
                    ['key' => 'billing_expiring_enabled', 'label' => 'Завершення підписки', 'description' => 'Нагадування перед завершенням і після вимкнення.'],
                ],
            ],
            [
                'key' => 'verification',
                'title' => 'Верифікація та привʼязка',
                'description' => 'Усе, що стосується прав на профіль і підтвердження власника.',
                'items' => [
                    ['key' => 'verification_status_enabled', 'label' => 'Зміна статусу заявки', 'description' => 'Коли заявку підтверджено, відхилено або змінено її стан.'],
                    ['key' => 'verification_action_required_enabled', 'label' => 'Потрібна дія', 'description' => 'Коли треба дозавантажити документи або щось уточнити.'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapProfileNotification(PlatformNotification $notification): ?array
    {
        $meta = is_array($notification->meta) ? $notification->meta : [];
        $profileId = (int) ($meta['profile_id'] ?? 0);

        if ($profileId <= 0) {
            return null;
        }

        return [
            'id' => (int) $notification->id,
            'type' => (string) $notification->type,
            'title' => (string) $notification->title,
            'body' => filled($notification->body) ? (string) $notification->body : null,
            'created_at_label' => optional($notification->created_at)->format('d.m.Y H:i'),
            'is_read' => $notification->read_at !== null,
            'severity' => (string) ($meta['severity'] ?? $this->notificationSeverity($notification->type)),
            'action_url' => $meta['action_url'] ?? $this->notificationActionUrl((string) $notification->type, $profileId),
            'action_label' => $meta['action_label'] ?? $this->notificationActionLabel((string) $notification->type),
        ];
    }

    private function notificationSeverity(string $type): string
    {
        return match ($type) {
            'pro_review_negative', 'pro_billing_failed', 'pro_claim_rejected' => 'danger',
            'pro_profile_hidden', 'pro_billing_canceled', 'pro_claim_need_more_info' => 'warning',
            default => 'info',
        };
    }

    private function notificationActionUrl(string $type, int $profileId): string
    {
        return match ($type) {
            'pro_review_new', 'pro_review_negative', 'pro_review_unanswered' => route('pro.account', ['tab' => 'reviews', 'profile' => $profileId]),
            'pro_billing_paid', 'pro_billing_renewed', 'pro_billing_failed', 'pro_billing_expiring', 'pro_billing_canceled' => route('pro.account', ['tab' => 'billing', 'profile' => $profileId]),
            'pro_claim_approved', 'pro_claim_rejected', 'pro_claim_need_more_info', 'pro_claim_action_required' => route('pro.account', ['tab' => 'claims', 'profile' => $profileId]),
            'pro_profile_hidden', 'pro_profile_published' => route('pro.account', ['tab' => 'profile', 'profile' => $profileId]),
            default => route('pro.account', ['tab' => 'overview', 'profile' => $profileId]),
        };
    }

    private function notificationActionLabel(string $type): string
    {
        return match ($type) {
            'pro_review_new', 'pro_review_negative', 'pro_review_unanswered' => 'Відкрити відгуки',
            'pro_billing_paid', 'pro_billing_renewed', 'pro_billing_failed', 'pro_billing_expiring', 'pro_billing_canceled' => 'Відкрити оплату',
            'pro_claim_approved', 'pro_claim_rejected', 'pro_claim_need_more_info', 'pro_claim_action_required' => 'Відкрити заявку',
            'pro_profile_hidden', 'pro_profile_published' => 'Відкрити профіль',
            default => 'Переглянути',
        };
    }

    private function buildActionItems(
        Profile $profile,
        ?ProSubscription $subscription,
        mixed $claim,
        array $reviewStatusSummary,
        int $completionPercent,
        ?array $analytics = null
    ): array {
        $now = now();
        $publishedReviews = $profile->reviews()
            ->where('status', 'published')
            ->with('officialReply')
            ->get(['id', 'rating', 'created_at', 'published_at', 'external_source_type']);

        // Імпортовані відгуки не вважаємо «боргом без відповіді» — інакше
        // власник у перший день бачить десятки завдань, які фізично не закрити.
        $reviewsWithoutReply = $publishedReviews->filter(
            fn ($review) => ! $review->officialReply && $review->external_source_type === null
        );
        $negativeWithoutReply = $reviewsWithoutReply->where('rating', '<=', 2);
        $recentNegativeWithoutReply = $negativeWithoutReply->filter(function ($review) use ($now) {
            $reviewedAt = $review->published_at ?? $review->created_at;

            return $reviewedAt && $reviewedAt->greaterThanOrEqualTo($now->copy()->subHours(48));
        });
        $ratingDrop = $this->resolveRatingDrop($profile, $publishedReviews);
        $viewsDiffPercent = (float) data_get($analytics ?? [], 'metrics.views.diff_percent', 0);
        $viewsIsUp = (bool) data_get($analytics ?? [], 'metrics.views.is_up', true);
        $pendingReviewsCount = (int) ($reviewStatusSummary['pending'] ?? 0);
        $publishedReviewsCount = (int) ($reviewStatusSummary['published'] ?? $publishedReviews->count());

        $publicationIssue = $profile->status !== 'active' || ! $profile->is_published || ! $profile->show_in_catalog;
        $missingDescription = blank($profile->description);
        $missingContacts = ! (filled($profile->phone) || filled($profile->email) || filled($profile->website) || filled($profile->contact_cta_url));
        $missingServices = ! $profile->services()->exists();
        $missingLogo = blank($profile->logo_url);
        $missingFieldsCount = count(array_filter([
            $missingDescription,
            $missingContacts,
            $missingServices,
            $missingLogo,
        ]));

        $subscriptionStatus = (string) ($subscription?->status ?? '');
        $daysToEnd = $subscription?->ends_at ? (int) $now->diffInDays($subscription->ends_at, false) : null;
        $billingIssue = in_array($subscriptionStatus, ['payment_failed', 'past_due', 'unpaid'], true);
        $expiredIssue = in_array($subscriptionStatus, ['expired', 'canceled'], true) || ($daysToEnd !== null && $daysToEnd < 0);
        $expiringSoon = $daysToEnd !== null && $daysToEnd >= 0 && $daysToEnd <= 14;
        $expiringUrgent = $daysToEnd !== null && $daysToEnd >= 0 && $daysToEnd <= 7;

        $viewsCurrent = (int) ($analytics['views'] ?? 0);
        $totalClicks = (int) (($analytics['website_clicks'] ?? 0) + ($analytics['contact_clicks'] ?? 0));
        $ctr = (float) ($analytics['ctr'] ?? 0);
        $noTrafficIssue = ! $publicationIssue && $viewsCurrent === 0;
        $viewsDrop = ! $viewsIsUp && $viewsDiffPercent <= -25;
        $conversionIssue = $viewsCurrent >= 25 && $totalClicks === 0;
        $lowCtr = $viewsCurrent >= 25 && $totalClicks > 0 && $ctr < 1.5;

        // A flat list of concrete problems. Every card is actionable, its
        // title matches its content, and it deep-links to the tab (and
        // section) where the problem is actually fixed. No success/state
        // cards — "all clear" is rendered by the overview itself.
        $issues = [];

        // --- Reviews: the most severe applicable state only. ---
        if ($recentNegativeWithoutReply->count() > 0) {
            $issues[] = $this->makeAttentionCard(
                'reviews',
                'Негативні відгуки без відповіді',
                'fa-comments',
                'danger',
                $recentNegativeWithoutReply->count(),
                'Відповідайте якнайшвидше — свіжий негатив найбільше впливає на довіру',
                route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id]),
                'reviews'
            );
        } elseif ($negativeWithoutReply->count() > 0) {
            $issues[] = $this->makeAttentionCard(
                'reviews',
                'Негативні відгуки без відповіді',
                'fa-comments',
                'warning',
                $negativeWithoutReply->count(),
                'Дайте офіційні відповіді на негативні відгуки',
                route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id]),
                'reviews'
            );
        } elseif ($reviewsWithoutReply->count() > 0) {
            $issues[] = $this->makeAttentionCard(
                'reviews',
                'Відгуки без відповіді',
                'fa-comments',
                'info',
                $reviewsWithoutReply->count(),
                'Відгуки чекають офіційної відповіді від профілю',
                route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id]),
                'reviews'
            );
        } elseif ($publishedReviewsCount === 0 && $pendingReviewsCount === 0) {
            $issues[] = $this->makeAttentionCard(
                'reviews',
                'Перші відгуки',
                'fa-star',
                'info',
                0,
                'Попросіть клієнтів залишити перші відгуки — профілі з відгуками отримують більше звернень',
                route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id]),
                'reviews'
            );
        }

        if ($ratingDrop !== null) {
            $issues[] = $this->makeAttentionCard(
                'rating',
                'Рейтинг знижується',
                'fa-arrow-trend-down',
                'warning',
                0,
                'Середній рейтинг просів з ' . number_format($ratingDrop['from'], 1) . ' до ' . number_format($ratingDrop['to'], 1) . ' — відповіді на негатив допомагають його підняти',
                route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id]),
                'reviews'
            );
        }

        // --- Profile: publication beats missing fields. ---
        if ($publicationIssue) {
            $issues[] = $this->makeAttentionCard(
                'profile',
                'Профіль не опубліковано',
                'fa-eye-slash',
                'warning',
                0,
                'Профіль не видно в каталозі — увімкніть публікацію в налаштуваннях',
                route('pro.account', ['tab' => 'profile', 'profile' => $profile->id]),
                'profile'
            );
        } elseif ($missingFieldsCount > 0) {
            $issues[] = $this->makeAttentionCard(
                'profile',
                'Незаповнені дані',
                'fa-pen-to-square',
                ($missingContacts || $missingServices) ? 'warning' : 'info',
                $missingFieldsCount,
                $missingLogo && $missingFieldsCount === 1
                    ? 'Додайте логотип або фото для більшої довіри'
                    : $this->profileFieldsSummary($missingDescription, $missingContacts, $missingServices),
                route('pro.account', ['tab' => 'profile', 'profile' => $profile->id]),
                'profile'
            );
        }

        // --- Traffic: one card max, most actionable first. ---
        if (! $publicationIssue) {
            if ($conversionIssue) {
                $issues[] = $this->makeAttentionCard(
                    'traffic',
                    'Перегляди без звернень',
                    'fa-hand-pointer',
                    'warning',
                    0,
                    $viewsCurrent . ' переглядів і жодного кліку — додайте телефон, сайт або кнопку звʼязку',
                    route('pro.account', ['tab' => 'profile', 'profile' => $profile->id]),
                    'profile',
                    'pro-profile-contacts-section'
                );
            } elseif ($viewsDrop) {
                $issues[] = $this->makeAttentionCard(
                    'traffic',
                    'Перегляди падають',
                    'fa-arrow-trend-down',
                    'warning',
                    0,
                    'Мінус ' . abs((int) round($viewsDiffPercent)) . '% переглядів за період — перегляньте аналітику джерел',
                    route('pro.account', ['tab' => 'analytics', 'profile' => $profile->id]),
                    'analytics'
                );
            } elseif ($noTrafficIssue) {
                $issues[] = $this->makeAttentionCard(
                    'traffic',
                    'Немає переглядів',
                    'fa-chart-line',
                    'info',
                    0,
                    'За 30 днів жодного перегляду — перевірте категорію, місто та фото профілю',
                    route('pro.account', ['tab' => 'profile', 'profile' => $profile->id]),
                    'profile'
                );
            } elseif ($lowCtr) {
                $issues[] = $this->makeAttentionCard(
                    'traffic',
                    'Низька конверсія',
                    'fa-hand-pointer',
                    'info',
                    0,
                    'CTR лише ' . rtrim(rtrim(number_format($ctr, 1, '.', ''), '0'), '.') . '% — підсиліть опис і контактну кнопку',
                    route('pro.account', ['tab' => 'profile', 'profile' => $profile->id]),
                    'profile',
                    'pro-profile-contacts-section'
                );
            }
        }

        // --- Billing: one card max. Informational "підписка активна"
        // states live in the sidebar, not here. ---
        if ($billingIssue) {
            $issues[] = $this->makeAttentionCard(
                'billing',
                'Проблема з оплатою',
                'fa-credit-card',
                'danger',
                0,
                'Оновіть спосіб оплати, щоб не втратити PRO-функції',
                route('pro.account', ['tab' => 'billing', 'profile' => $profile->id]),
                'billing'
            );
        } elseif ($expiredIssue) {
            $issues[] = $this->makeAttentionCard(
                'billing',
                'Підписка неактивна',
                'fa-credit-card',
                'danger',
                0,
                'Поновіть підписку, щоб повернути PRO-функції',
                route('pro.account', ['tab' => 'billing', 'profile' => $profile->id]),
                'billing'
            );
        } elseif ($expiringUrgent && $subscription?->ends_at) {
            $issues[] = $this->makeAttentionCard(
                'billing',
                'Підписка закінчується',
                'fa-credit-card',
                'warning',
                0,
                'Подовжіть до ' . $subscription->ends_at->format('d.m.Y') . ' — залишилось ' . max(0, $daysToEnd) . ' дн.',
                route('pro.account', ['tab' => 'billing', 'profile' => $profile->id]),
                'billing'
            );
        } elseif ($expiringSoon && $subscription?->ends_at) {
            $issues[] = $this->makeAttentionCard(
                'billing',
                'Підписка закінчується',
                'fa-credit-card',
                'info',
                0,
                'Діє до ' . $subscription->ends_at->format('d.m.Y') . ' — подовжіть заздалегідь',
                route('pro.account', ['tab' => 'billing', 'profile' => $profile->id]),
                'billing'
            );
        } elseif (! $subscription && ! ($profile->is_pro ?? false)) {
            $issues[] = $this->makeAttentionCard(
                'billing',
                'Немає PRO-підписки',
                'fa-credit-card',
                'warning',
                0,
                'PRO-функції обмежені — оформіть підписку для повного доступу',
                route('pro.account', ['tab' => 'billing', 'profile' => $profile->id]),
                'billing'
            );
        }

        // --- Verification: only when the OWNER still has something to do.
        // Profiles in the cabinet are already linked, so "подайте заявку"
        // prompts are obsolete; an in-review claim needs no action either. ---
        $claimStatus = (string) ($claim?->status ?? '');
        if ($claim && in_array($claimStatus, ['pending', 'need_more_info'], true) && blank($claim->proof_document_url)) {
            $issues[] = $this->makeAttentionCard(
                'verification',
                'Верифікація профілю',
                'fa-shield-halved',
                'info',
                0,
                'Додайте документи до заявки, щоб завершити перевірку',
                route('pro.account', ['tab' => 'claims', 'profile' => $profile->id]),
                'claims'
            );
        }

        $toneRank = ['danger' => 0, 'warning' => 1, 'info' => 2];

        return collect($issues)
            ->sortBy(fn ($issue) => $toneRank[$issue['tone']] ?? 3)
            ->values()
            ->take(4)
            ->all();
    }

    private function makeAttentionCard(
        string $key,
        string $title,
        string $icon,
        string $tone,
        int $badgeCount,
        string $summary,
        string $url,
        string $tab = 'overview',
        ?string $scroll = null
    ): array {
        return [
            'key' => $key,
            'title' => $title,
            'icon' => $icon,
            'tone' => $tone,
            'badge_count' => max(0, $badgeCount),
            'summary' => $summary,
            'url' => $url,
            'tab' => $tab,
            'scroll' => $scroll,
            'items' => [$summary],
        ];
    }

    /**
     * @param  Collection<int, ProfileReview>  $reviews
     * @return array<int, array<string, mixed>>
     */
    private function mapPublicProfileReviews(Profile $profile, Collection $reviews): array
    {
        return $reviews
            ->map(function (ProfileReview $review) use ($profile) {
                $author = $review->author_name ?: 'Користувач DOVIRA';
                $avatar = mb_strtoupper(mb_substr(trim($author), 0, 1));
                $publishedAt = $review->published_at ?: $review->created_at;
                $officialReply = null;

                if ($review->officialReply) {
                    $officialReply = [
                        'author' => $review->officialReply->author?->name ?: $profile->name,
                        'text' => $review->officialReply->body,
                        'date' => optional($review->officialReply->created_at)->translatedFormat('d F Y') ?: now()->translatedFormat('d F Y'),
                    ];
                }

                return [
                    'id' => (int) $review->id,
                    'author' => $author,
                    'meta' => '1 відгук · ' . ($profile->city ?: 'Україна'),
                    'date' => optional($publishedAt)->translatedFormat('d F Y') ?: now()->translatedFormat('d F Y'),
                    'date_iso' => optional($publishedAt)?->toDateString(),
                    'rating' => (float) $review->rating,
                    'title' => $review->title,
                    'text' => $review->body,
                    'avatar' => $avatar,
                    'avatar_url' => MediaUrl::avatarUrl($review->external_review_author_avatar_url ?: $review->author?->avatar_url, $author, 96),
                    'media' => collect((array) ($review->media ?? []))
                        ->map(fn (string $path) => MediaUrl::publicUrl($path))
                        ->filter()
                        ->values()
                        ->all(),
                    'external_source_label' => $this->externalReviewSourceLabel($review->external_source_type, $review->external_source_url),
                    'external_source_type' => $review->external_source_type,
                    'external_source_url' => $review->resolved_external_source_url,
                    'official_reply' => $officialReply,
                ];
            })
            ->values()
            ->all();
    }

    private function externalReviewSourceLabel(?string $type, ?string $url): ?string
    {
        $normalizedType = trim((string) $type);

        return match ($normalizedType) {
            'google_maps', 'google', 'google_business' => 'Google',
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'telegram' => 'Telegram',
            default => filled($url) ? 'Зовнішнє джерело' : null,
        };
    }

    public function formatTrendDisplay(array $trend, string $periodShortLabel): array
    {
        $current = (float) ($trend['current'] ?? 0);
        $previous = (float) ($trend['previous'] ?? 0);
        $delta = $current - $previous;
        $isUp = $delta >= 0;
        $periodContext = mb_strtolower($periodShortLabel);
        $absoluteDelta = abs($delta);
        $formattedAbsoluteDelta = number_format($absoluteDelta, $absoluteDelta >= 10 ? 0 : 1, '.', ' ');
        $formattedAbsoluteDelta = rtrim(rtrim($formattedAbsoluteDelta, '0'), '.');
        $formattedCurrent = number_format($current, $current >= 10 ? 0 : 1, '.', ' ');
        $formattedCurrent = rtrim(rtrim($formattedCurrent, '0'), '.');

        if ($current <= 0 && $previous <= 0) {
            return [
                'kind' => 'flat',
                'is_up' => true,
                'value' => '0',
                'detail' => 'без змін',
            ];
        }

        if ($previous <= 0 && $current > 0) {
            return [
                'kind' => 'new',
                'is_up' => true,
                'value' => '+' . $formattedCurrent,
                'detail' => 'нові дані ' . $periodContext,
            ];
        }

        if ($delta === 0.0) {
            return [
                'kind' => 'flat',
                'is_up' => true,
                'value' => '0',
                'detail' => 'без змін vs попер. період',
            ];
        }

        if ($previous < 50 || max($current, $previous) < 100) {
            return [
                'kind' => 'absolute',
                'is_up' => $isUp,
                'value' => ($isUp ? '+' : '-') . $formattedAbsoluteDelta,
                'detail' => 'vs попер. період',
            ];
        }

        $diffPercent = $previous > 0 ? round(($delta / $previous) * 100, 1) : 0.0;
        $formattedPercent = rtrim(rtrim(number_format(abs($diffPercent), 1, '.', ''), '0'), '.');

        return [
            'kind' => 'percent',
            'is_up' => $isUp,
            'value' => ($isUp ? '+' : '-') . $formattedPercent . '%',
            'detail' => 'vs попер. період',
        ];
    }

    public function analyticsPeriodOptions(): array
    {
        return [
            ['key' => 'today', 'label' => 'Сьогодні', 'short_label' => 'За сьогодні'],
            ['key' => 'last_7', 'label' => 'Останні 7 днів', 'short_label' => 'За 7 днів'],
            ['key' => 'last_30', 'label' => 'Останні 30 днів', 'short_label' => 'За 30 днів'],
            ['key' => 'last_90', 'label' => 'Останні 90 днів', 'short_label' => 'За 90 днів'],
        ];
    }

    public function normalizeAnalyticsPeriod(string $period): string
    {
        return in_array($period, ['today', 'last_7', 'last_30', 'last_90'], true)
            ? $period
            : 'last_30';
    }

    private function buildAnalyticsActionRows(
        Profile $profile,
        ?CarbonImmutable $fromAt,
        ?CarbonImmutable $toAt,
        string $periodShortLabel
    ): array {
        if (! $fromAt || ! $toAt) {
            $toAt = CarbonImmutable::now()->endOfDay();
            $fromAt = $toAt->subDays(29)->startOfDay();
        }

        $days = max(1, $fromAt->startOfDay()->diffInDays($toAt->startOfDay()) + 1);
        $prevStart = $fromAt->subDays($days);
        $prevEnd = $fromAt->subSecond();

        $rows = [
            [
                'icon' => 'fa-phone',
                'label' => 'Натискання на телефон',
                'types' => ['phone_click'],
            ],
            [
                'icon' => 'fa-envelope',
                'label' => 'Натискання на email',
                'types' => ['email_click'],
            ],
            [
                'icon' => 'fa-arrow-up-right-from-square',
                'label' => 'Переходи на сайт',
                'types' => ['website_click'],
            ],
            [
                'icon' => 'fa-location-dot',
                'label' => 'Побудова маршруту',
                'types' => ['map_click'],
            ],
            [
                'icon' => 'fa-share-nodes',
                'label' => 'Кліки на соцмережі',
                'types' => ['telegram_click', 'viber_click', 'whatsapp_click', 'instagram_click', 'facebook_click'],
            ],
        ];

        return collect($rows)
            ->map(function (array $row) use ($profile, $fromAt, $toAt, $prevStart, $prevEnd, $periodShortLabel): array {
                $current = ProfileEvent::query()
                    ->where('profile_id', $profile->id)
                    ->whereIn('event_type', $row['types'])
                    ->whereBetween('created_at', [$fromAt, $toAt])
                    ->count();

                $previous = ProfileEvent::query()
                    ->where('profile_id', $profile->id)
                    ->whereIn('event_type', $row['types'])
                    ->whereBetween('created_at', [$prevStart, $prevEnd])
                    ->count();

                return [
                    'icon' => $row['icon'],
                    'label' => $row['label'],
                    'value' => (int) $current,
                    'trend' => $this->formatTrendDisplay([
                        'current' => (int) $current,
                        'previous' => (int) $previous,
                    ], $periodShortLabel),
                ];
            })
            ->values()
            ->all();
    }

    private function buildReviewsRatingTimeline(
        Profile $profile,
        ?CarbonImmutable $fromAt,
        ?CarbonImmutable $toAt
    ): array {
        if (! $fromAt || ! $toAt) {
            $toAt = CarbonImmutable::now()->endOfDay();
            $fromAt = $toAt->subDays(29)->startOfDay();
        }

        $reviews = $profile->reviews()
            ->where('status', 'published')
            ->where(function ($query) use ($fromAt, $toAt): void {
                $query->whereBetween('published_at', [$fromAt, $toAt])
                    ->orWhere(function ($fallback) use ($fromAt, $toAt): void {
                        $fallback->whereNull('published_at')
                            ->whereBetween('created_at', [$fromAt, $toAt]);
                    });
            })
            ->get(['id', 'rating', 'created_at', 'published_at']);

        $days = max(1, $fromAt->startOfDay()->diffInDays($toAt->startOfDay()) + 1);
        $labels = [];
        $reviewCounts = [];
        $ratingValues = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $fromAt->startOfDay()->addDays($i);
            $dayReviews = $reviews->filter(function (ProfileReview $review) use ($date): bool {
                $reviewedAt = $review->published_at ?: $review->created_at;

                return $reviewedAt && $reviewedAt->toDateString() === $date->toDateString();
            });

            $labels[] = $date->format('d.m');
            $reviewCounts[] = $dayReviews->count();
            $ratingValues[] = $dayReviews->isNotEmpty()
                ? round((float) $dayReviews->avg('rating'), 1)
                : null;
        }

        return [
            'labels' => $labels,
            'review_counts' => $reviewCounts,
            'rating_values' => $ratingValues,
        ];
    }

    /**
     * Ліди (заявки) профілю для кабінету. Контакти non-PRO користувача
     * маскуються ще на сервері — щоб реальні телефони не потрапляли в DOM
     * без підписки (гейт монетизації).
     */
    private function buildLeadsData(Profile $profile, bool $isActivePro): array
    {
        if (! Schema::hasTable('profile_leads')) {
            return ['available' => false, 'total' => 0, 'unread' => 0, 'items' => [], 'is_pro' => $isActivePro];
        }

        $isPro = $isActivePro || (bool) ($profile->is_pro ?? false);

        $base = \App\Models\ProfileLead::query()->where('profile_id', $profile->id);
        $total = (clone $base)->count();
        $unread = (clone $base)->where('is_read', false)->count();

        $items = (clone $base)->latest()->limit(50)->get()->map(fn ($lead): array => [
            'id' => $lead->id,
            'name' => $isPro ? $lead->name : $this->maskName((string) $lead->name),
            'phone' => $isPro ? $lead->phone : $this->maskPhone((string) $lead->phone),
            'message' => $isPro ? $lead->message : null,
            'status' => $lead->status,
            'is_read' => (bool) $lead->is_read,
            'date' => $lead->created_at?->translatedFormat('d M Y, H:i') ?: $lead->created_at?->format('d.m.Y H:i'),
        ])->all();

        return ['available' => true, 'total' => $total, 'unread' => $unread, 'items' => $items, 'is_pro' => $isPro];
    }

    private function maskName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '•••';
        }

        return mb_substr($name, 0, 1) . str_repeat('•', 4);
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $tail = mb_substr((string) $digits, -2);

        return '••• ••• •• ' . ($tail !== '' ? $tail : '••');
    }

    private function buildBillingSummary(Profile $profile, ?ProSubscription $subscription): array
    {
        $plan = (string) ($subscription?->plan ?: 'basic');
        $status = (string) ($subscription?->status ?: 'inactive');
        $billingPeriod = in_array((string) ($subscription?->billing_period ?? \App\Support\ProPricing::PERIOD_KEY), ['month', 'year', \App\Support\ProPricing::PERIOD_KEY], true)
            ? (string) ($subscription?->billing_period ?? \App\Support\ProPricing::PERIOD_KEY)
            : \App\Support\ProPricing::PERIOD_KEY;
        $currency = (string) ($subscription?->currency ?: \App\Support\ProPricing::CURRENCY);
        // Ціна підписки зафіксована за нею самою (стартова пропозиція);
        // якщо своєї ще немає — поточна ціна з ProPricing.
        $priceMonthly = (int) ($subscription?->price_monthly ?? 0) > 0
            ? (int) $subscription->price_monthly
            : \App\Support\ProPricing::CURRENT_AMOUNT;
        $priceYearly = (int) ($subscription?->price_yearly ?? 0) > 0
            ? (int) $subscription->price_yearly
            : \App\Support\ProPricing::CURRENT_AMOUNT;
        $isActive = $subscription
            && $status === 'active'
            && (! $subscription->ends_at || $subscription->ends_at->isFuture());

        // Безкоштовний тариф у картках нижче зветься «Start» — заголовок мусить
        // збігатися, інакше «Поточний план: Basic» суперечить «Start · активний».
        $planLabel = match ($plan) {
            'pro' => 'PRO',
            'business' => 'Business',
            'enterprise' => 'Enterprise',
            default => 'Start',
        };

        $statusLabel = match ($status) {
            'active' => 'Активний',
            'payment_failed', 'past_due', 'unpaid' => 'Проблема з оплатою',
            'expired' => 'Закінчився',
            'canceled' => 'Скасовано',
            default => $isActive ? 'Активний' : 'Неактивний',
        };

        $statusTone = match ($status) {
            'active' => 'success',
            'payment_failed', 'past_due', 'unpaid' => 'danger',
            'expired', 'canceled' => 'warning',
            default => $isActive ? 'success' : 'muted',
        };

        $nextPaymentAt = $isActive ? $subscription?->ends_at : null;
        $payments = [];
        // price_monthly зберігає ціну за оплачений період (для halfyear —
        // за всі 6 місяців), тож сума разова для будь-якого періоду.
        $paidAmount = $billingPeriod === 'year' ? $priceYearly : $priceMonthly;
        $paymentRows = collect();

        if (Schema::hasTable('pro_subscription_payments')) {
            $paymentRows = $profile->proSubscriptionPayments()
                ->latest('paid_at')
                ->latest('id')
                ->limit(6)
                ->get();
        }

        if ($paymentRows->isNotEmpty()) {
            $payments = $paymentRows
                ->map(function ($payment): array {
                    $paidAt = $payment->paid_at ?: $payment->created_at;

                    return [
                        'date' => $paidAt?->translatedFormat('d F Y') ?? 'Без дати',
                        'description' => (string) $payment->description,
                        'amount' => $this->formatMoney((int) $payment->amount, (string) ($payment->currency ?: 'UAH')),
                        'status' => match ((string) $payment->status) {
                            'paid' => 'Оплачено',
                            'refunded' => 'Повернено',
                            'failed' => 'Помилка',
                            default => 'Обробляється',
                        },
                        'status_tone' => match ((string) $payment->status) {
                            'paid' => 'success',
                            'refunded' => 'warning',
                            'failed' => 'danger',
                            default => 'info',
                        },
                        'document_url' => route('pro.account.billing.receipt', $payment),
                        'reference' => (string) $payment->reference,
                    ];
                })
                ->all();
        } elseif ($subscription?->started_at && $paidAmount > 0) {
            $cursor = $subscription->started_at->copy();
            $periodMonths = match ($billingPeriod) {
                'year' => 12,
                \App\Support\ProPricing::PERIOD_KEY => \App\Support\ProPricing::PERIOD_MONTHS,
                default => 1,
            };
            $end = min(3, max(1, intdiv($cursor->diffInMonths(now()), $periodMonths) + 1));

            for ($i = 0; $i < $end; $i++) {
                $paymentDate = $cursor->copy()->addMonths($i * $periodMonths);
                if ($paymentDate->isFuture()) {
                    continue;
                }

                $payments[] = [
                    'date' => $paymentDate->translatedFormat('d F Y'),
                    'description' => 'Підписка ' . $planLabel . ' — ' . $paymentDate->translatedFormat('F Y'),
                    'amount' => $this->formatMoney($paidAmount, $currency),
                    'status' => 'Оплачено',
                    'status_tone' => 'success',
                    'document_url' => null,
                    'reference' => null,
                ];
            }
        }

        $payments = array_slice($payments, 0, 6);

        return [
            'plan' => $plan,
            'plan_label' => $planLabel,
            'status' => $status,
            'status_label' => $statusLabel,
            'status_tone' => $statusTone,
            'is_active' => (bool) $isActive,
            'billing_period' => $billingPeriod,
            'billing_period_label' => match ($billingPeriod) {
                'year' => 'Щорічна оплата',
                \App\Support\ProPricing::PERIOD_KEY => 'Оплата раз на 6 місяців',
                default => 'Щомісячна оплата',
            },
            // Поки підписка не активна — користувач на безкоштовному Start,
            // тож поточна ціна 0, а не прайс PRO.
            'current_price_label' => $isActive ? $this->formatMoney($paidAmount, $currency) : $this->formatMoney(0, $currency),
            'next_charge_amount_label' => $isActive ? $this->formatMoney($paidAmount, $currency) : $this->formatMoney(0, $currency),
            'can_cancel' => (bool) $isActive,
            'price_monthly' => $this->formatMoney($priceMonthly, $currency),
            'price_yearly' => $this->formatMoney($priceYearly, $currency),
            'next_payment_label' => $nextPaymentAt ? $nextPaymentAt->translatedFormat('d F Y') : 'Не заплановано',
            'started_label' => $subscription?->started_at?->translatedFormat('d F Y') ?: 'Немає даних',
            'ended_label' => $subscription?->ends_at?->translatedFormat('d F Y') ?: 'Немає даних',
            'provider_label' => 'monopay',
            'mode_label' => 'Оплата підтверджується провайдером',
            'payments' => $payments,
        ];
    }

    private function formatMoney(int $amount, string $currency = 'UAH'): string
    {
        $suffix = match (strtoupper($currency)) {
            'UAH' => 'грн',
            'USD' => '$',
            'EUR' => '€',
            default => strtoupper($currency),
        };

        if ($amount <= 0) {
            return '0 ' . $suffix;
        }

        return number_format($amount, 0, '.', ' ') . ' ' . $suffix;
    }

    private function buildPublishedReviewsMetric(
        Profile $profile,
        ?CarbonImmutable $fromAt,
        ?CarbonImmutable $toAt
    ): array {
        $publishedReviews = $profile->reviews()
            ->where('status', 'published')
            ->get(['id', 'created_at', 'published_at']);

        if (! $fromAt || ! $toAt) {
            $current = $publishedReviews->count();

            return [
                'current' => $current,
                'previous' => 0,
                'diff_percent' => $current > 0 ? 100.0 : 0.0,
                'is_up' => $current >= 0,
            ];
        }

        $days = max(1, $fromAt->startOfDay()->diffInDays($toAt->startOfDay()) + 1);
        $prevStart = $fromAt->subDays($days);
        $prevEnd = $fromAt->subSecond();

        $current = $publishedReviews->filter(function ($review) use ($fromAt, $toAt) {
            $reviewedAt = $review->published_at ?? $review->created_at;

            return $reviewedAt
                && $reviewedAt->greaterThanOrEqualTo($fromAt)
                && $reviewedAt->lessThanOrEqualTo($toAt);
        })->count();

        $previous = $publishedReviews->filter(function ($review) use ($prevStart, $prevEnd) {
            $reviewedAt = $review->published_at ?? $review->created_at;

            return $reviewedAt
                && $reviewedAt->greaterThanOrEqualTo($prevStart)
                && $reviewedAt->lessThanOrEqualTo($prevEnd);
        })->count();

        $diff = $current - $previous;
        $diffPercent = $previous > 0 ? round(($diff / $previous) * 100, 1) : ($current > 0 ? 100.0 : 0.0);

        return [
            'current' => $current,
            'previous' => $previous,
            'diff_percent' => (float) $diffPercent,
            'is_up' => $diff >= 0,
        ];
    }

    private function profileFieldsSummary(bool $missingDescription, bool $missingContacts, bool $missingServices): string
    {
        $missing = collect([
            $missingDescription ? 'опис' : null,
            $missingContacts ? 'контакти' : null,
            $missingServices ? 'послуги' : null,
        ])->filter()->values();

        if ($missing->count() >= 2) {
            return 'Заповніть обов’язкові поля профілю';
        }

        return match ($missing->first()) {
            'опис' => 'Додайте опис профілю',
            'контакти' => 'Додайте контакти для звернень клієнтів',
            'послуги' => 'Додайте послуги для пошуку у фільтрах',
            default => 'Оновіть основні дані профілю',
        };
    }

    private function resolveRatingDrop(Profile $profile, Collection $publishedReviews): ?array
    {
        $recentWindowStart = now()->subDays(7);
        $recentReviews = $publishedReviews->filter(function ($review) use ($recentWindowStart) {
            $reviewedAt = $review->published_at ?? $review->created_at;

            return $reviewedAt && $reviewedAt->greaterThanOrEqualTo($recentWindowStart);
        });

        $olderReviews = $publishedReviews->filter(function ($review) use ($recentWindowStart) {
            $reviewedAt = $review->published_at ?? $review->created_at;

            return $reviewedAt && $reviewedAt->lt($recentWindowStart);
        });

        if ($recentReviews->isEmpty() || $olderReviews->count() < 3) {
            return null;
        }

        $previousAverage = round((float) $olderReviews->avg('rating'), 1);
        $currentAverage = round((float) $profile->rating_avg, 1);

        if (($previousAverage - $currentAverage) < 0.3) {
            return null;
        }

        return [
            'from' => $previousAverage,
            'to' => $currentAverage,
        ];
    }

    private function buildSourceSummary(array $series): array
    {
        $mapped = [];

        foreach ($series as $key => $values) {
            $total = array_sum(array_map('intval', (array) $values));
            if ($total <= 0) {
                continue;
            }

            $mapped[] = [
                'label' => match ((string) $key) {
                    'google' => 'Google',
                    'facebook' => 'Facebook',
                    'internal' => 'Внутрішні переходи',
                    default => 'Інші джерела',
                },
                'value' => $total,
            ];
        }

        usort($mapped, fn (array $left, array $right) => $right['value'] <=> $left['value']);

        $sum = array_sum(array_column($mapped, 'value'));

        return array_map(function (array $item) use ($sum): array {
            $item['share'] = $sum > 0 ? (int) round(($item['value'] / $sum) * 100) : 0;

            return $item;
        }, $mapped);
    }

    private function normalizeDeviceTypes(array $deviceTypes): array
    {
        $mapped = [];
        $sum = array_sum(array_map('intval', $deviceTypes));

        foreach ($deviceTypes as $type => $count) {
            $count = (int) $count;
            if ($count <= 0) {
                continue;
            }

            $mapped[] = [
                'label' => match ((string) $type) {
                    'mobile' => 'Мобільні',
                    'desktop' => 'Десктоп',
                    'tablet' => 'Планшети',
                    default => 'Невідомо',
                },
                'value' => $count,
                'share' => $sum > 0 ? (int) round(($count / $sum) * 100) : 0,
            ];
        }

        usort($mapped, fn (array $left, array $right) => $right['value'] <=> $left['value']);

        return $mapped;
    }
}
