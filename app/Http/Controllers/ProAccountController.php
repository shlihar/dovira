<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\CategoryService;
use App\Models\OfficialReply;
use App\Models\PaymentOrder;
use App\Models\Profile;
use App\Models\ProfileClaim;
use App\Models\ProfileReview;
use App\Models\ProSubscription;
use App\Models\ProSubscriptionPayment;
use App\Models\Region;
use App\Models\ReviewReport;
use App\Services\Payments\MonopayClient;
use App\Services\Payments\PaymentFulfillmentService;
use App\Services\ProfileAnalyticsService;
use App\Services\ProProfileNotificationService;
use App\Support\AuditLogger;
use App\Support\CategoryHierarchy;
use App\Support\MediaUrl;
use App\Support\ProfileSeo;
use App\Support\RegionCityDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ProAccountController extends Controller
{
    /** Скільки відгуків власник може приховати на один профіль. */
    public const HIDDEN_REVIEWS_LIMIT = 5;

    public function __invoke(Request $request): View
    {
        $claimProfileId = ($id = (int) $request->query('claim_profile')) > 0 ? $id : null;

        // Новий власник заходить за посиланням на конкретний профіль, але дію
        // (OTP-підтвердження / оплата) блокує гейт verified. Запамʼятовуємо ціль,
        // щоб повернути його рівно сюди після підтвердження email
        // (див. VerifyEmailController) — інакше він губить профіль на верифікації.
        if ($claimProfileId && ($user = $request->user()) && ! $user->hasVerifiedEmail()) {
            $request->session()->put('claim_intent_profile', $claimProfileId);
        }

        return view('pro.account', [
            'initialState' => [
                'tab' => (string) $request->query('tab', 'overview'),
                'selectedProfileId' => ($profileId = (int) $request->query('profile')) > 0 ? $profileId : null,
                'analyticsPeriod' => (string) $request->query('analytics_period', 'last_30'),
                'claimSearch' => trim((string) $request->query('q', $request->query('claim_query', ''))),
                'selectedClaimProfileId' => $claimProfileId,
            ],
        ]);
    }

    public function analytics(Request $request): JsonResponse
    {
        $analyticsPeriod = $this->normalizeAnalyticsPeriod((string) $request->query('analytics_period', 'last_30'));
        $selectedProfileId = (int) $request->query('profile');

        $ownedProfiles = $request->user()->ownedProfiles()
            ->orderByDesc('is_pro')
            ->orderBy('name')
            // is_pro потрібен PRO-гейту нижче: без нього hasActiveProSubscription
            // не бачить прапорця і дарма ходить у таблицю підписок.
            ->get(['id', 'name', 'is_pro']);

        $currentProfile = $selectedProfileId > 0
            ? $ownedProfiles->firstWhere('id', $selectedProfileId)
            : $ownedProfiles->first();

        abort_unless($currentProfile instanceof Profile, 404);
        // Аналітика — частина платного PRO-кабінету.
        abort_unless($currentProfile->hasActiveProSubscription(), 403);

        $analytics = app(ProfileAnalyticsService::class)->analyticsSummary($currentProfile, $analyticsPeriod);

        return response()->json([
            'chart' => $this->buildChartPayload($analytics, $analyticsPeriod),
            'summary' => [
                'value' => number_format((int) data_get($analytics, 'metrics.views.current', 0), 0, '.', ' '),
                'subtitle' => 'переглядів ' . mb_strtolower((string) data_get($analytics, 'period.short_label', 'за 30 днів')),
                'trend' => $this->formatTrendDisplay(
                    (array) data_get($analytics, 'metrics.views', []),
                    (string) data_get($analytics, 'period.short_label', 'За 30 днів')
                ),
                'period_label' => (string) data_get($analytics, 'period.short_label', 'За 30 днів'),
                'has_meaningful_data' => collect(data_get($analytics, 'metrics.views.sparkline', []))->max() > 0,
            ],
        ]);
    }

    public function purchaseSubscription(Request $request, Profile $profile): RedirectResponse|JsonResponse
    {
        $this->assertBillableProfile($request, $profile);

        $request->validate([
            'plan' => ['nullable', Rule::in(['pro'])],
            'period' => ['nullable', Rule::in([\App\Support\ProPricing::PERIOD_KEY])],
        ]);

        $redirectUrl = route('pro.account.billing.pay', $profile);

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'redirect',
                'message' => 'Оберіть спосіб оплати PRO.',
                'profile_id' => $profile->id,
                'redirect_url' => $redirectUrl,
            ]);
        }

        return redirect()->to($redirectUrl);
    }

    public function showBillingPayment(Request $request, Profile $profile): View
    {
        $this->assertBillableProfile($request, $profile);

        return view('pay.pro', [
            'profile' => $profile,
            'product' => $this->proPaymentProduct($profile),
        ]);
    }

    public function startBillingMonopay(Request $request, Profile $profile): RedirectResponse|JsonResponse
    {
        $this->assertBillableProfile($request, $profile);

        if ($redirect = $this->ensureMonopayAvailable($request, $profile)) {
            return $redirect;
        }

        $product = $this->proPaymentProduct($profile);

        $order = PaymentOrder::create([
            'user_id' => $request->user()->id,
            'profile_id' => $profile->id,
            'token' => (string) Str::uuid(),
            'method' => PaymentOrder::METHOD_MONOPAY,
            'product_type' => PaymentOrder::PRODUCT_PRO_SUBSCRIPTION,
            'status' => PaymentOrder::STATUS_PENDING,
            'amount' => (string) ((int) config('payments.pro.amount') * 100),
            'currency' => (string) config('payments.pro.currency'),
            'description' => $product['name'],
            'meta' => ['price_uah' => (int) config('payments.pro.amount')],
        ]);

        try {
            $invoice = MonopayClient::make()->createInvoice([
                'amount' => (int) $order->amount,
                'ccy' => (int) config('payments.pro.currency_code'),
                'merchantPaymInfo' => [
                    'reference' => $order->token,
                    'destination' => $product['name'] . ' — ' . $profile->name,
                    'comment' => $product['description'],
                ],
                'redirectUrl' => route('pro.account.billing.success', $order->token),
                'webHookUrl' => route('webhooks.monopay'),
                'validity' => (int) config('payments.pro.validity'),
                'paymentType' => 'debit',
                'withAppUrl' => true,
            ]);

            $paymentUrl = $this->monopayRedirectUrl($request, $invoice);

            if ($paymentUrl === '') {
                throw new \RuntimeException('Monopay invoice response is missing payment URL.');
            }
        } catch (Throwable $exception) {
            report($exception);

            $order->forceFill([
                'status' => PaymentOrder::STATUS_FAILED,
                'meta' => array_merge((array) ($order->meta ?? []), [
                    'monopay_error' => $exception->getMessage(),
                ]),
            ])->save();

            return $this->monopayPaymentFailureResponse(
                $request,
                $profile,
                'pro-billing-provider-error',
                'monopay не створив платіж. Спробуйте ще раз або зверніться в підтримку.'
            );
        }

        $order->update(['provider_invoice_id' => (string) $invoice['invoiceId']]);

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'redirect',
                'redirect_url' => $paymentUrl,
            ]);
        }

        return redirect()->away($paymentUrl);
    }

    public function billingPaymentSuccess(Request $request, string $token): View
    {
        $order = PaymentOrder::where('token', $token)
            ->where('product_type', PaymentOrder::PRODUCT_PRO_SUBSCRIPTION)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if (! $order->isPaid() && $order->provider_invoice_id) {
            $invoice = rescue(
                fn () => MonopayClient::make()->getInvoice($order->provider_invoice_id),
                null,
                false,
            );

            if (($invoice['status'] ?? null) === 'success') {
                $order = app(PaymentFulfillmentService::class)->markOrderPaid($order, [
                    'provider_charge_id' => (string) ($invoice['invoiceId'] ?? $order->provider_invoice_id),
                    'meta' => array_merge((array) ($order->meta ?? []), [
                        'monopay_status' => $invoice,
                    ]),
                ]);
            }
        }

        abort_unless($order->profile instanceof Profile, 404);

        return view('pay.pro-success', [
            'order' => $order,
            'profile' => $order->profile,
            'product' => $this->proPaymentProduct($order->profile),
        ]);
    }

    /**
     * Відписка бізнесу від холодних листів-тригерів (signed-URL з листа).
     * Записуємо opt-out у notification_preferences — без окремої таблиці.
     */
    public function unsubscribeOutreach(Request $request, Profile $profile): View
    {
        $prefs = (array) ($profile->notification_preferences ?? []);
        $prefs['outreach_opted_out'] = true;
        $profile->forceFill(['notification_preferences' => $prefs])->save();

        return view('static.simple-message', [
            'title' => 'Готово',
            'message' => 'Ми більше не надсилатимемо листи про відгуки на цю адресу.',
        ]);
    }

    public function cancelTestSubscription(Request $request, Profile $profile): RedirectResponse|JsonResponse
    {
        $this->assertOwnedProfile($request, $profile);

        $subscription = $profile->proSubscriptions()
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->latest('started_at')
            ->latest('id')
            ->first();

        if (! $subscription || $subscription->status !== 'active') {
            $redirectUrl = route('pro.account', ['tab' => 'billing', 'profile' => $profile->id]);

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'redirect',
                    'message' => 'Для цього профілю вже немає активної підписки.',
                    'profile_id' => $profile->id,
                    'subscription_status' => 'inactive',
                    'redirect_url' => $redirectUrl,
                ]);
            }

            return redirect()
                ->to($redirectUrl)
                ->with('status', 'pro-billing-already-inactive');
        }

        $subscription->forceFill([
            'status' => 'canceled',
            'ends_at' => now(),
            'canceled_at' => now(),
        ])->save();

        $profile->forceFill(['is_pro' => false])->save();

        app(ProProfileNotificationService::class)->create(
            $profile,
            'pro_billing_canceled',
            'PRO-підписку вимкнено',
            'Підписка для цього профілю завершена.',
            [
                'severity' => 'warning',
                'action_url' => route('pro.account', ['tab' => 'billing', 'profile' => $profile->id]),
            ]
        );

        $redirectUrl = route('pro.account', ['tab' => 'billing', 'profile' => $profile->id]);

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'redirect',
                'message' => 'PRO-підписку вимкнено.',
                'profile_id' => $profile->id,
                'subscription_status' => $subscription->status,
                'redirect_url' => $redirectUrl,
            ]);
        }

        return redirect()
            ->to($redirectUrl)
            ->with('status', 'pro-billing-canceled');
    }

    public function downloadBillingReceipt(Request $request, ProSubscriptionPayment $payment)
    {
        $payment->loadMissing('profile');
        abort_unless($payment->profile instanceof Profile, 404);
        $this->assertOwnedProfile($request, $payment->profile);

        $paidAt = $payment->paid_at ?: $payment->created_at;
        $content = implode("\n", [
            'DOVIRA Receipt',
            '----------------------',
            'Reference: ' . $payment->reference,
            'Profile ID: ' . $payment->profile_id,
            'Plan: ' . strtoupper((string) $payment->plan),
            'Period: ' . match ((string) $payment->billing_period) {
                'year' => 'YEAR',
                \App\Support\ProPricing::PERIOD_KEY => 'HALFYEAR',
                default => 'MONTH',
            },
            'Amount: ' . number_format((int) $payment->amount, 0, '.', ' ') . ' ' . strtoupper((string) $payment->currency),
            'Status: ' . strtoupper((string) $payment->status),
            'Provider: ' . strtoupper((string) $payment->provider),
            'Paid at: ' . ($paidAt ? $paidAt->format('Y-m-d H:i:s') : 'N/A'),
            'Description: ' . (string) $payment->description,
        ]);

        return response()->streamDownload(
            static function () use ($content): void {
                echo $content;
            },
            sprintf('dovira-receipt-%s.txt', Str::lower((string) $payment->reference)),
            ['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }

    public function updateProfile(Request $request, Profile $profile): RedirectResponse|JsonResponse
    {
        $this->assertOwnedProfile($request, $profile);

        // Редагування профілю (включно з досьє) — лише з активною PRO-підпискою.
        if (! $profile->hasActiveProSubscription()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Редагування профілю доступне лише з PRO-підпискою.',
                    'redirect_url' => route('pro.account.billing.pay', $profile),
                ], 403);
            }

            return redirect()
                ->route('pro.account.billing.pay', $profile)
                ->with('status', 'pro-edit-requires-pro');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('profiles', 'slug')->ignore($profile->id)],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where(fn ($query) => $query->whereNull('parent_id')->where('is_active', true))],
            'subcategory_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer', Rule::exists('category_services', 'id')],
            'service_names' => ['nullable', 'array'],
            'service_names.*' => ['nullable', 'string', 'max:120'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:10000'],
            'dossier' => ['nullable', 'string', 'max:20000'],
            'logo_url' => ['nullable', 'string', 'max:2048'],
            'logo_file' => ['nullable', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp,image/gif', 'max:10240'],
            'banner_url' => ['nullable', 'string', 'max:2048'],
            'gallery_urls' => ['nullable', 'string', 'max:10000'],
            'gallery_files' => ['nullable', 'array', 'max:12'],
            'gallery_files.*' => ['file', 'image', 'mimetypes:image/jpeg,image/png,image/webp,image/gif', 'max:10240'],
            'website' => ['nullable', 'url', 'max:2048'],
            'contact_cta_url' => ['nullable', 'string', 'max:2048', function ($attribute, $value, $fail) {
                if (filled($value) && \App\Support\WebsiteUrl::href($value) === null) {
                    $fail('Вкажіть коректне посилання (наприклад, t.me/nickname або https://instagram.com/...).');
                }
            }],
            'contact_cta_mode' => ['nullable', \Illuminate\Validation\Rule::in(['link', 'lead_form'])],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'social_facebook' => ['nullable', 'url', 'max:2048'],
            'social_instagram' => ['nullable', 'url', 'max:2048'],
            'social_telegram' => ['nullable', 'url', 'max:2048'],
            'social_youtube' => ['nullable', 'url', 'max:2048'],
            'social_viber' => ['nullable', 'url', 'max:2048'],
            'social_whatsapp' => ['nullable', 'url', 'max:2048'],
            'owner_profile_experience_years' => ['nullable', 'integer', 'min:0', 'max:99'],
            'owner_profile_consultations_count' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'owner_profile_response_speed' => ['nullable', 'string', 'max:60'],
            'owner_profile_experience' => ['nullable', 'string', 'max:5000'],
            'owner_profile_faq_json' => ['nullable', 'string', 'max:40000'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in(['active', 'hidden', 'draft'])],
            'show_in_catalog' => ['nullable', 'boolean'],
            'publish_profile' => ['nullable', 'boolean'],
        ]);

        $regions = Region::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $resolvedCity = RegionCityDirectory::canonicalCity($this->nullableString($validated['city'] ?? null));
        $inferredRegionId = RegionCityDirectory::inferRegionId($resolvedCity, $regions);

        $publishProfile = $request->boolean('publish_profile');
        $status = $publishProfile ? 'active' : $validated['status'];
        $showInCatalog = $publishProfile ? true : $request->boolean('show_in_catalog');
        $categoryId = isset($validated['category_id']) && $validated['category_id'] !== null
            ? (int) $validated['category_id']
            : null;
        $subcategoryId = isset($validated['subcategory_id']) && $validated['subcategory_id'] !== null
            ? (int) $validated['subcategory_id']
            : null;
        $subcategoryId = CategoryHierarchy::resolveValidSubcategoryId($categoryId, $subcategoryId);
        $serviceCategoryId = $categoryId ? CategoryHierarchy::resolveRootCategoryId($categoryId) : null;
        $serviceIds = collect($validated['service_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();
        $serviceNames = $this->normalizeServiceNames($validated['service_names'] ?? []);
        $currentPrimaryCategory = CategoryHierarchy::primaryCategory($profile);
        $currentCategoryName = $currentPrimaryCategory?->name;
        $nextCategoryName = $subcategoryId
            ? Category::query()->whereKey($subcategoryId)->value('name')
            : ($categoryId ? Category::query()->whereKey($categoryId)->value('name') : $currentCategoryName);
        $nextSeoServiceNames = $serviceNames !== []
            ? $serviceNames
            : $profile->services()->orderBy('name')->pluck('name')->all();
        $currentServiceNames = $profile->services()->orderBy('name')->pluck('name')->all();
        $submittedSeoTitle = $this->nullableString($validated['seo_title'] ?? null);
        $submittedSeoDescription = $this->nullableString($validated['seo_description'] ?? null);
        $generatedSeoTitle = ProfileSeo::defaultTitle(
            trim((string) $validated['name']),
            $nextCategoryName,
            $resolvedCity
        );
        $generatedSeoDescription = ProfileSeo::defaultDescription(
            trim((string) $validated['name']),
            $nextCategoryName,
            $resolvedCity,
            $nextSeoServiceNames,
            $this->nullableString($validated['short_description'] ?? null),
            $this->nullableString($validated['description'] ?? null)
        );
        $resolvedSeoTitle = ProfileSeo::isAutoGeneratedTitle(
            $submittedSeoTitle,
            $profile->name,
            $currentCategoryName,
            $profile->city
        ) ? $generatedSeoTitle : $submittedSeoTitle;
        $resolvedSeoDescription = ProfileSeo::isAutoGeneratedDescription(
            $submittedSeoDescription,
            $profile->name,
            $currentCategoryName,
            $profile->city,
            $currentServiceNames,
            $profile->short_description,
            $profile->description
        ) ? $generatedSeoDescription : $submittedSeoDescription;

        $previousGallery = collect($this->normalizeStoredGalleryEntries($profile->gallery ?? []))
            ->pluck('url')
            ->filter()
            ->values()
            ->all();
        $previousLogo = trim((string) ($profile->logo_url ?? ''));
        $nextLogo = $this->storeLogoFile($profile, $validated['logo_file'] ?? null)
            ?: $this->nullableString($validated['logo_url'] ?? null);
        $previousStatus = (string) ($profile->status ?? '');
        $previousPublishedState = (bool) ($profile->is_published ?? false) && (bool) ($profile->show_in_catalog ?? false);

        $profile->fill([
            'name' => trim((string) $validated['name']),
            'slug' => trim((string) $validated['slug']),
            'short_description' => $this->nullableString($validated['short_description'] ?? null),
            'description' => $this->nullableString($validated['description'] ?? null),
            'dossier' => array_key_exists('dossier', $validated)
                ? $this->nullableString($validated['dossier'])
                : $profile->dossier,
            'logo_url' => $nextLogo,
            'banner_url' => $this->nullableString($validated['banner_url'] ?? null),
            // OG-зображення для шерингу — завжди логотип профілю (окремого поля
            // на фронті більше немає).
            'og_image_url' => $nextLogo,
            'website' => $this->nullableString($validated['website'] ?? null),
            'contact_cta_url' => \App\Support\WebsiteUrl::href($validated['contact_cta_url'] ?? null),
            // Ключ додаємо лише коли колонка вже змігрована (деплой ручний).
            ...(\Illuminate\Support\Facades\Schema::hasColumn('profiles', 'contact_cta_mode') ? [
                'contact_cta_mode' => in_array($validated['contact_cta_mode'] ?? 'link', ['link', 'lead_form'], true)
                    ? ($validated['contact_cta_mode'] ?? 'link')
                    : 'link',
            ] : []),
            'email' => $this->nullableString($validated['email'] ?? null),
            'phone' => $this->nullableString($validated['phone'] ?? null),
            'address' => $this->nullableString($validated['address'] ?? null),
            'city' => $resolvedCity,
            'region_id' => $inferredRegionId ?? ($validated['region_id'] ?? null),
            'seo_title' => $resolvedSeoTitle,
            'seo_description' => $resolvedSeoDescription,
            'status' => $status,
            'show_in_catalog' => $showInCatalog,
            'is_published' => $status === 'active',
            'updated_by_user_id' => $request->user()->id,
        ]);

        $profile->gallery = array_values([
            ...$this->normalizeGalleryEntries($validated['gallery_urls'] ?? null),
            ...$this->storeGalleryFiles($profile, $validated['gallery_files'] ?? []),
        ]);
        $profile->social_links = $this->normalizeSocialLinks(
            (array) $profile->social_links,
            [
                'facebook' => $validated['social_facebook'] ?? null,
                'instagram' => $validated['social_instagram'] ?? null,
                'telegram' => $validated['social_telegram'] ?? null,
                'youtube' => $validated['social_youtube'] ?? null,
                'viber' => $validated['social_viber'] ?? null,
                'whatsapp' => $validated['social_whatsapp'] ?? null,
            ]
        );
        $profile->ai_suggested_data = $this->mergeOwnerProfileData(
            (array) ($profile->ai_suggested_data ?? []),
            $validated
        );

        // Правка досьє власником → позначка «owner»: на публічній сторінці
        // бейдж чесно показує, що текст більше не авторства ШІ/редакції.
        if ($profile->isDirty('dossier')) {
            $profile->dossier_source = filled($profile->dossier) ? 'owner' : null;
            $profile->dossier_generated_at = now();
        }

        $profile->save();
        $currentPublishedState = (bool) ($profile->is_published ?? false) && (bool) ($profile->show_in_catalog ?? false);

        if (($previousStatus !== $profile->status || $previousPublishedState !== $currentPublishedState) && $profile->owner_user_id) {
            if ($profile->status === 'active' && $currentPublishedState) {
                app(ProProfileNotificationService::class)->create(
                    $profile,
                    'pro_profile_published',
                    'Профіль опубліковано',
                    'Профіль знову доступний у каталозі та на публічній сторінці.',
                    [
                        'severity' => 'success',
                        'action_url' => route('pro.account', ['tab' => 'overview', 'profile' => $profile->id]),
                    ]
                );
            } elseif ($profile->status !== 'active' || ! $currentPublishedState) {
                app(ProProfileNotificationService::class)->create(
                    $profile,
                    'pro_profile_hidden',
                    'Профіль не показується в каталозі',
                    'Перевірте статус публікації та налаштування видимості профілю.',
                    [
                        'severity' => 'warning',
                        'action_url' => route('pro.account', ['tab' => 'profile', 'profile' => $profile->id]),
                    ]
                );
            }
        }
        $this->deleteRemovedGalleryFiles(
            $previousGallery,
            collect($this->normalizeStoredGalleryEntries($profile->gallery ?? []))
                ->pluck('url')
                ->filter()
                ->values()
                ->all()
        );
        $this->deleteStaleManagedMediaPath($previousLogo, [
            (string) ($profile->logo_url ?? ''),
            (string) ($profile->banner_url ?? ''),
            ...collect($this->normalizeStoredGalleryEntries($profile->gallery ?? []))
                ->pluck('url')
                ->map(static fn ($item) => (string) $item)
                ->all(),
        ]);

        CategoryHierarchy::syncProfileCategories($profile, $categoryId, $subcategoryId);

        if ($serviceNames !== [] && ! $categoryId) {
            throw ValidationException::withMessages([
                'category_id' => 'Спочатку оберіть категорію, щоб додати послуги.',
            ]);
        }

        $allowedServiceIds = [];
        if ($serviceCategoryId && $serviceIds !== []) {
            $allowedServiceIds = CategoryService::query()
                ->where('category_id', $serviceCategoryId)
                ->whereIn('id', $serviceIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }
        if ($serviceCategoryId && $serviceNames !== []) {
            $allowedServiceIds = $this->resolveProfileServiceIds($serviceCategoryId, $allowedServiceIds, $serviceNames);
        }
        $profile->services()->sync($allowedServiceIds);

        AuditLogger::log('pro.profile_updated', $profile, [
            'updated_by_owner' => $request->user()->id,
        ]);

        if ($request->expectsJson()) {
            $profile->load([
                'categories:id,parent_id,name',
                'region:id,name',
                'services:id,name',
            ]);

            $primaryCategory = CategoryHierarchy::primaryCategory($profile);

            return response()->json([
                'status' => 'ok',
                'message' => 'Зміни збережено.',
                'profile' => [
                    'id' => $profile->id,
                    'name' => $profile->name,
                    'slug' => $profile->slug,
                    'short_description' => $profile->short_description,
                    'description' => $profile->description,
                    'city' => $profile->city,
                    'region_id' => $profile->region_id,
                    'region' => $profile->region?->name,
                    'category' => $primaryCategory?->parent?->name ?? $primaryCategory?->name,
                    'subcategory' => $primaryCategory?->parent?->name ? $primaryCategory?->name : null,
                    'phone' => $profile->phone,
                    'email' => $profile->email,
                    'website' => $profile->website,
                    'address' => $profile->address,
                    'status' => $profile->status,
                    'show_in_catalog' => (bool) $profile->show_in_catalog,
                    'is_published' => (bool) $profile->is_published,
                    'seo_title' => $profile->seo_title,
                    'seo_description' => $profile->seo_description,
                    'services' => $profile->services->pluck('name')->values(),
                    'social_links' => $profile->social_links ?? [],
                    'owner_profile' => $this->resolveOwnerProfileData((array) ($profile->ai_suggested_data ?? [])),
                    'media' => [
                        'logo_url' => $profile->logo_url,
                        'logo_public_url' => MediaUrl::profileLogoUrl($profile, 240),
                        'banner_url' => $profile->banner_url,
                        'banner_public_url' => MediaUrl::publicImageUrl($profile->banner_url),
                        'gallery' => collect($this->normalizeStoredGalleryEntries($profile->gallery ?? []))
                            ->pluck('url')
                            ->filter()
                            ->values()
                            ->all(),
                        'gallery_items' => collect($this->normalizeStoredGalleryEntries($profile->gallery ?? []))
                            ->map(fn ($item) => [
                                'raw' => (string) ($item['url'] ?? ''),
                                'url' => MediaUrl::publicImageUrl((string) ($item['url'] ?? '')),
                                'visible' => (bool) ($item['visible'] ?? true),
                                'title' => (string) ($item['title'] ?? ''),
                            ])
                            ->filter(fn ($item) => filled($item['url']))
                            ->values(),
                    ],
                ],
            ]);
        }

        return redirect()
            ->route('pro.account', ['tab' => 'profile', 'profile' => $profile->id])
            ->with('status', 'pro-profile-updated');
    }

    public function reportReview(Request $request, ProfileReview $review): JsonResponse
    {
        $review->loadMissing('profile');
        $profile = $review->profile;
        abort_unless($profile instanceof Profile, 404);
        $this->assertOwnedProfile($request, $profile);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:120'],
            'details' => ['nullable', 'string', 'max:1000'],
        ]);

        $report = ReviewReport::query()->firstOrCreate(
            [
                'profile_review_id' => $review->id,
                'reporter_user_id' => $request->user()->id,
                'status' => 'open',
            ],
            [
                'reason' => trim($validated['reason']),
                'details' => trim((string) ($validated['details'] ?? '')),
            ]
        );

        AuditLogger::log('pro.review_reported', $review, [
            'profile_id' => $review->profile_id,
            'report_id' => $report->id,
            'reason' => $report->reason,
        ]);

        return response()->json([
            'status' => 'ok',
            'message' => 'Скаргу надіслано на модерацію.',
            'review_id' => $review->id,
        ]);
    }

    public function reviewDetail(Request $request, ProfileReview $review): JsonResponse
    {
        $review->loadMissing(['profile', 'author:id,name,avatar_url', 'officialReply.author:id,name']);
        $profile = $review->profile;
        abort_unless($profile instanceof Profile, 404);
        $this->assertOwnedProfile($request, $profile);

        return response()->json([
            'status' => 'ok',
            'review_id' => $review->id,
            'html' => view('pro.partials.review-detail-panel', [
                'review' => $review,
                'currentProfile' => $profile,
                'canManageReviewModeration' => $profile->hasActiveProSubscription(),
                'isLivewireProAccount' => true,
            ])->render(),
        ]);
    }

    public function upsertReply(Request $request, ProfileReview $review): RedirectResponse|JsonResponse
    {
        $review->loadMissing('profile');
        $profile = $review->profile;
        abort_unless($profile instanceof Profile, 404);
        $this->assertOwnedProfile($request, $profile);
        $this->assertCanManageReviewModeration($profile);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $reply = OfficialReply::query()->firstOrNew([
            'profile_review_id' => $review->id,
        ]);

        $isExisting = $reply->exists;
        $reply->fill([
            'profile_id' => $review->profile_id,
            'author_user_id' => $request->user()->id,
            'body' => trim((string) $validated['body']),
        ]);

        if ($isExisting) {
            $reply->is_edited = true;
            $reply->edited_at = now();
        }

        $reply->save();

        AuditLogger::log('pro.review_reply_saved', $review, [
            'profile_id' => $review->profile_id,
            'reply_id' => $reply->id,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Офіційну відповідь збережено.',
                'review_id' => $review->id,
                'redirect_url' => route('pro.account', ['tab' => 'reviews', 'profile' => $review->profile_id]),
            ]);
        }

        return redirect()
            ->route('pro.account', ['tab' => 'reviews', 'profile' => $review->profile_id])
            ->with('status', 'pro-review-reply-saved');
    }

    public function updateReviewVisibility(Request $request, ProfileReview $review): RedirectResponse|JsonResponse
    {
        $review->loadMissing('profile');
        $profile = $review->profile;
        abort_unless($profile instanceof Profile, 404);
        $this->assertOwnedProfile($request, $profile);
        // Керування видимістю відгуків — частина платного керування профілем.
        $this->assertCanManageReviewModeration($profile);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['published', 'hidden'])],
        ]);

        // Ліміт приховувань: власник може сховати максимум 5 відгуків на
        // профіль. Це не дає ховати всю критику. Розкриття — завжди дозволено.
        if ($validated['status'] === 'hidden' && $review->status !== 'hidden') {
            $hiddenCount = $profile->reviews()->where('status', 'hidden')->count();

            if ($hiddenCount >= self::HIDDEN_REVIEWS_LIMIT) {
                $message = sprintf(
                    'Можна приховати не більше %d відгуків. Спершу поверніть якийсь із прихованих або поскаржтесь на модерацію.',
                    self::HIDDEN_REVIEWS_LIMIT
                );

                if ($request->expectsJson()) {
                    return response()->json(['status' => 'error', 'message' => $message], 422);
                }

                return redirect()
                    ->route('pro.account', ['tab' => 'reviews', 'profile' => $review->profile_id])
                    ->withErrors(['review_visibility' => $message]);
            }
        }

        $payload = ['status' => $validated['status']];
        if ($validated['status'] === 'published' && ! $review->published_at) {
            $payload['published_at'] = now();
        }

        $review->update($payload);

        AuditLogger::log('pro.review_visibility_updated', $review, [
            'profile_id' => $review->profile_id,
            'status' => $validated['status'],
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Видимість відгуку оновлено.',
                'review_id' => $review->id,
                'review_status' => $review->status,
                'redirect_url' => route('pro.account', ['tab' => 'reviews', 'profile' => $review->profile_id]),
            ]);
        }

        return redirect()
            ->route('pro.account', ['tab' => 'reviews', 'profile' => $review->profile_id])
            ->with('status', 'pro-review-visibility-updated');
    }

    public function updateNotificationPreferences(Request $request, Profile $profile): RedirectResponse|JsonResponse
    {
        $this->assertOwnedProfile($request, $profile);

        $service = app(ProProfileNotificationService::class);
        $rules = collect($service->defaults())
            ->mapWithKeys(fn ($value, $key) => [(string) $key => ['nullable', 'boolean']])
            ->all();

        $validated = $request->validate($rules);
        $preferences = $service->update($profile, $validated);

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Налаштування сповіщень збережено.',
                'preferences' => $preferences,
            ]);
        }

        return redirect()
            ->route('pro.account', ['tab' => 'notifications', 'profile' => $profile->id])
            ->with('status', 'pro-profile-updated');
    }

    public function markNotificationsRead(Request $request, Profile $profile): RedirectResponse|JsonResponse
    {
        $this->assertOwnedProfile($request, $profile);

        app(ProProfileNotificationService::class)->markAllAsRead($profile);

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Сповіщення позначено як прочитані.',
            ]);
        }

        return redirect()
            ->route('pro.account', ['tab' => 'notifications', 'profile' => $profile->id]);
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
            ->get(['id', 'rating', 'created_at', 'published_at']);

        $reviewsWithoutReply = $publishedReviews->filter(fn ($review) => ! $review->officialReply);
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
        $profileIssueCount = count(array_filter([
            $publicationIssue,
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
        $claimHasProof = $claim && filled($claim->proof_document_url);
        $needsVerification = ! $profile->is_verified;
        $needsClaim = $needsVerification && ! $claim;
        $needsProof = $needsVerification && (bool) $claim && ! $claimHasProof;
        $verificationInReview = $needsVerification && (bool) $claim && $claimHasProof;
        $statusIssueCount = count(array_filter([
            $billingIssue,
            $expiredIssue,
            $expiringSoon,
            $needsVerification,
            $needsProof,
        ]));

        $viewsCurrent = (int) ($analytics['views'] ?? 0);
        $totalClicks = (int) (($analytics['website_clicks'] ?? 0) + ($analytics['contact_clicks'] ?? 0));
        $ctr = (float) ($analytics['ctr'] ?? 0);
        $noTrafficIssue = ! $publicationIssue && $viewsCurrent === 0;
        $viewsDrop = ! $viewsIsUp && $viewsDiffPercent <= -25;
        $conversionIssue = $viewsCurrent >= 25 && $totalClicks === 0;
        $lowCtr = $viewsCurrent >= 25 && $totalClicks > 0 && $ctr < 1.5;
        $opportunityIssueCount = count(array_filter([
            $noTrafficIssue,
            $conversionIssue,
            $viewsDrop,
            $ratingDrop !== null,
            $lowCtr,
        ]));

        $reviewsCard = match (true) {
            $recentNegativeWithoutReply->count() > 0 => $this->makeAttentionCard(
                'reviews',
                'Відгуки без відповіді',
                'fa-comments',
                'danger',
                $recentNegativeWithoutReply->count(),
                $recentNegativeWithoutReply->count() === 1
                    ? 'Відповідайте на новий негативний відгук якнайшвидше'
                    : 'Дайте відповіді на ' . $recentNegativeWithoutReply->count() . ' нові негативні відгуки',
                route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id])
            ),
            $negativeWithoutReply->count() > 0 => $this->makeAttentionCard(
                'reviews',
                'Відгуки без відповіді',
                'fa-comments',
                'warning',
                $negativeWithoutReply->count(),
                'Дайте відповіді на ' . $negativeWithoutReply->count() . ' негативні відгуки',
                route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id])
            ),
            $reviewsWithoutReply->count() > 0 => $this->makeAttentionCard(
                'reviews',
                'Відгуки без відповіді',
                'fa-comments',
                'info',
                $reviewsWithoutReply->count(),
                'Закрийте ' . $reviewsWithoutReply->count() . ' відгуки без офіційної відповіді',
                route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id])
            ),
            $pendingReviewsCount > 0 => $this->makeAttentionCard(
                'reviews',
                'Відгуки без відповіді',
                'fa-comments',
                'info',
                $pendingReviewsCount,
                $pendingReviewsCount . ' відгуки ще проходять модерацію',
                route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id])
            ),
            $publishedReviewsCount === 0 => $this->makeAttentionCard(
                'reviews',
                'Відгуки без відповіді',
                'fa-comments',
                'info',
                0,
                'Попросіть перші відгуки від клієнтів',
                route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id])
            ),
            default => $this->makeAttentionCard(
                'reviews',
                'Відгуки без відповіді',
                'fa-comments',
                'success',
                0,
                'Усі відгуки вже опрацьовані',
                route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id])
            ),
        };

        $opportunitiesCard = match (true) {
            $noTrafficIssue => $this->makeAttentionCard(
                'opportunities',
                'Трафік і конверсія',
                'fa-chart-line',
                'warning',
                max(1, $opportunityIssueCount),
                'За 30 днів профіль не отримав переглядів',
                route('pro.account', ['tab' => 'overview', 'profile' => $profile->id]) . '#overview-analytics'
            ),
            $conversionIssue => $this->makeAttentionCard(
                'opportunities',
                'Трафік і конверсія',
                'fa-chart-line',
                'warning',
                max(1, $opportunityIssueCount),
                $viewsCurrent . ' переглядів і 0 кліків. Додайте сильний CTA та контакти',
                route('pro.account', ['tab' => 'overview', 'profile' => $profile->id]) . '#overview-analytics'
            ),
            $viewsDrop => $this->makeAttentionCard(
                'opportunities',
                'Трафік і конверсія',
                'fa-chart-line',
                'warning',
                max(1, $opportunityIssueCount),
                'Перегляди впали на ' . abs((int) round($viewsDiffPercent)) . '%. Оновіть фото, опис і відгуки',
                route('pro.account', ['tab' => 'overview', 'profile' => $profile->id]) . '#overview-analytics'
            ),
            $ratingDrop !== null => $this->makeAttentionCard(
                'opportunities',
                'Трафік і конверсія',
                'fa-chart-line',
                'info',
                max(1, $opportunityIssueCount),
                'Рейтинг просів до ' . number_format($ratingDrop['to'], 1) . ', це може знижувати конверсію профілю',
                route('pro.account', ['tab' => 'overview', 'profile' => $profile->id]) . '#overview-analytics'
            ),
            $lowCtr => $this->makeAttentionCard(
                'opportunities',
                'Трафік і конверсія',
                'fa-chart-line',
                'info',
                max(1, $opportunityIssueCount),
                'CTR лише ' . rtrim(rtrim(number_format($ctr, 1, '.', ''), '0'), '.') . '%. Підсиліть опис і кнопку контакту',
                route('pro.account', ['tab' => 'profile', 'profile' => $profile->id])
            ),
            default => $this->makeAttentionCard(
                'opportunities',
                'Трафік і конверсія',
                'fa-chart-line',
                'success',
                0,
                'Трафік і конверсія профілю зараз стабільні',
                route('pro.account', ['tab' => 'overview', 'profile' => $profile->id]) . '#overview-analytics'
            ),
        };

        $profileCard = match (true) {
            $publicationIssue => $this->makeAttentionCard(
                'profile',
                'Незаповнені дані',
                'fa-pen-to-square',
                'warning',
                max(1, $profileIssueCount),
                'Опублікуйте профіль, щоб він з’явився в каталозі',
                route('pro.account', ['tab' => 'profile', 'profile' => $profile->id])
            ),
            ($missingDescription || $missingContacts || $missingServices) && $missingLogo => $this->makeAttentionCard(
                'profile',
                'Незаповнені дані',
                'fa-pen-to-square',
                'warning',
                max(1, $profileIssueCount),
                'Додайте фото і заповніть базові поля профілю',
                route('pro.account', ['tab' => 'profile', 'profile' => $profile->id])
            ),
            $missingDescription || $missingContacts || $missingServices => $this->makeAttentionCard(
                'profile',
                'Незаповнені дані',
                'fa-pen-to-square',
                'info',
                max(1, $profileIssueCount),
                $this->profileFieldsSummary($missingDescription, $missingContacts, $missingServices),
                route('pro.account', ['tab' => 'profile', 'profile' => $profile->id])
            ),
            $missingLogo => $this->makeAttentionCard(
                'profile',
                'Незаповнені дані',
                'fa-pen-to-square',
                'info',
                max(1, $profileIssueCount),
                'Додайте фото або логотип для більшої довіри',
                route('pro.account', ['tab' => 'profile', 'profile' => $profile->id])
            ),
            $completionPercent < 90 => $this->makeAttentionCard(
                'profile',
                'Незаповнені дані',
                'fa-pen-to-square',
                'info',
                0,
                'Дозаповніть профіль до 90%+, зараз готовність ' . $completionPercent . '%',
                route('pro.account', ['tab' => 'profile', 'profile' => $profile->id])
            ),
            default => $this->makeAttentionCard(
                'profile',
                'Незаповнені дані',
                'fa-pen-to-square',
                'success',
                0,
                'Профіль заповнений і доступний у каталозі',
                route('pro.account', ['tab' => 'profile', 'profile' => $profile->id])
            ),
        };

        $statusCard = match (true) {
            $billingIssue => $this->makeAttentionCard(
                'status',
                'PRO-статус',
                'fa-credit-card',
                'danger',
                max(1, $statusIssueCount),
                'Оновіть оплату, щоб не втратити PRO-функції',
                route('pro')
            ),
            $expiredIssue => $this->makeAttentionCard(
                'status',
                'PRO-статус',
                'fa-credit-card',
                'danger',
                max(1, $statusIssueCount),
                'Поновіть підписку, щоб повернути PRO-функції',
                route('pro')
            ),
            $needsClaim => $this->makeAttentionCard(
                'status',
                'PRO-статус',
                'fa-credit-card',
                'warning',
                max(1, $statusIssueCount),
                'Подайте заявку на прив’язку і верифікацію профілю',
                route('pro.account', ['tab' => 'claims', 'profile' => $profile->id])
            ),
            $needsProof => $this->makeAttentionCard(
                'status',
                'PRO-статус',
                'fa-credit-card',
                'info',
                max(1, $statusIssueCount),
                'Додайте документи, щоб завершити верифікацію',
                route('pro.account', ['tab' => 'claims', 'profile' => $profile->id])
            ),
            $verificationInReview => $this->makeAttentionCard(
                'status',
                'PRO-статус',
                'fa-credit-card',
                'info',
                max(1, $statusIssueCount),
                'Заявка на верифікацію вже на розгляді',
                route('pro.account', ['tab' => 'claims', 'profile' => $profile->id])
            ),
            $expiringUrgent && $subscription?->ends_at => $this->makeAttentionCard(
                'status',
                'PRO-статус',
                'fa-credit-card',
                'warning',
                max(1, $statusIssueCount),
                'Подовжіть підписку до ' . $subscription->ends_at->format('d.m.Y'),
                route('pro')
            ),
            $expiringSoon && $subscription?->ends_at => $this->makeAttentionCard(
                'status',
                'PRO-статус',
                'fa-credit-card',
                'info',
                max(1, $statusIssueCount),
                'Підписка закінчується ' . $subscription->ends_at->format('d.m.Y'),
                route('pro')
            ),
            $subscription?->ends_at && $profile->is_verified => $this->makeAttentionCard(
                'status',
                'PRO-статус',
                'fa-credit-card',
                'success',
                0,
                'Підписка активна, профіль верифікований',
                route('pro')
            ),
            $subscription?->ends_at => $this->makeAttentionCard(
                'status',
                'PRO-статус',
                'fa-credit-card',
                'info',
                max(0, $statusIssueCount),
                'Підписка активна до ' . $subscription->ends_at->format('d.m.Y'),
                route('pro')
            ),
            ($profile->is_pro ?? false) => $this->makeAttentionCard(
                'status',
                'PRO-статус',
                'fa-credit-card',
                'info',
                max(0, $statusIssueCount),
                'PRO-статус активний для цього профілю',
                route('pro')
            ),
            default => $this->makeAttentionCard(
                'status',
                'PRO-статус',
                'fa-credit-card',
                'warning',
                1,
                'Для профілю немає активної PRO-підписки',
                route('pro')
            ),
        };

        return [
            $reviewsCard,
            $opportunitiesCard,
            $profileCard,
            $statusCard,
        ];
    }

    private function makeAttentionCard(
        string $key,
        string $title,
        string $icon,
        string $tone,
        int $badgeCount,
        string $summary,
        string $url
    ): array {
        return [
            'key' => $key,
            'title' => $title,
            'icon' => $icon,
            'tone' => $tone,
            'badge_count' => max(0, $badgeCount),
            'summary' => $summary,
            'url' => $url,
            'items' => [$summary],
        ];
    }

    private function assertCanManageReviewModeration(Profile $profile): void
    {
        abort_unless($profile->hasActiveProSubscription(), 403);
    }

    private function buildChartPayload(array $analytics, string $analyticsPeriod): array
    {
        $from = data_get($analytics, 'period.from');
        $values = collect(data_get($analytics, 'metrics.views.sparkline', []))
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();

        return [
            'period' => $analyticsPeriod,
            'periodLabel' => (string) data_get($analytics, 'period.short_label', 'За 30 днів'),
            'startDate' => $from instanceof \Carbon\CarbonInterface ? $from->toDateString() : now()->toDateString(),
            'values' => $values,
            'total' => (int) data_get($analytics, 'metrics.views.current', 0),
            'trendPercent' => (float) data_get($analytics, 'metrics.views.diff_percent', 0),
            'trendUp' => (bool) data_get($analytics, 'metrics.views.is_up', true),
        ];
    }

    /**
     * @param Collection<int, ProfileReview> $reviews
     * @return array<int, array{
     *   id:int,
     *   author:string,
     *   meta:string,
     *   date:string,
     *   date_iso:?string,
     *   rating:float,
     *   title:?string,
     *   text:string,
     *   avatar:string,
     *   avatar_url:?string,
     *   media:array<int,string>,
     *   external_source_label:?string,
     *   external_source_type:?string,
     *   external_source_url:?string,
     *   official_reply:?array{author:string,text:string,date:string}
     * }>
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
                    'avatar_url' => MediaUrl::avatarUrl($review->external_review_author_avatar_url, $author, 96),
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

    private function formatTrendDisplay(array $trend, string $periodShortLabel): array
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

    private function analyticsPeriodOptions(): array
    {
        return [
            ['key' => 'today', 'label' => 'Сьогодні', 'short_label' => 'За сьогодні'],
            ['key' => 'last_7', 'label' => 'Останні 7 днів', 'short_label' => 'За 7 днів'],
            ['key' => 'last_30', 'label' => 'Останні 30 днів', 'short_label' => 'За 30 днів'],
            ['key' => 'last_90', 'label' => 'Останні 90 днів', 'short_label' => 'За 90 днів'],
        ];
    }

    private function normalizeAnalyticsPeriod(string $period): string
    {
        return in_array($period, ['today', 'last_7', 'last_30', 'last_90'], true)
            ? $period
            : 'last_30';
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

    private function summarizeCompetitorAlert(string $message): string
    {
        $competitorName = trim((string) str($message)->before(' зараз має рейтинг '));

        if ($competitorName !== '') {
            return $competitorName . ' випереджає вас у категорії';
        }

        return 'Конкурент у категорії випереджає вас за метриками';
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

    private function resolveRatingDrop(Profile $profile, $publishedReviews): ?array
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

    private function formatClaimStatus(string $status): string
    {
        return match ($status) {
            'approved' => 'підтверджено',
            'pending' => 'очікує розгляду',
            'need_more_info' => 'потрібне уточнення',
            'rejected' => 'відхилено',
            default => 'невідомо',
        };
    }

    private function assertOwnedProfile(Request $request, Profile $profile): void
    {
        abort_unless((int) $profile->owner_user_id === (int) $request->user()->id, 403);
    }

    /**
     * Оплата PRO доступна власнику профілю АБО заявнику (профіль ще без
     * власника, але користувач подав заявку на привʼязку) — підтвердження
     * профілю відбувається лише після активації підписки.
     */
    private function assertBillableProfile(Request $request, Profile $profile): void
    {
        $userId = (int) $request->user()->id;

        if ((int) $profile->owner_user_id === $userId) {
            return;
        }

        $isClaimant = $profile->owner_user_id === null
            && ProfileClaim::query()
                ->where('profile_id', $profile->id)
                ->where('user_id', $userId)
                ->exists();

        abort_unless($isClaimant, 403);
    }

    /**
     * @return array<string, array{label:string,halfyear:array{label:string,amount:int}}>
     */
    private function proBillingCatalog(): array
    {
        // Стартова пропозиція: один тариф PRO на 6 місяців. Ціна береться з
        // ProPricing — при завершенні акції міняється лише константа.
        return [
            'pro' => [
                'label' => 'PRO',
                \App\Support\ProPricing::PERIOD_KEY => [
                    'label' => \App\Support\ProPricing::PERIOD_LABEL,
                    'amount' => \App\Support\ProPricing::CURRENT_AMOUNT,
                ],
            ],
        ];
    }

    private function proPaymentProduct(Profile $profile): array
    {
        $amount = (int) config('payments.pro.amount');
        $priceDisplay = $amount === \App\Support\ProPricing::CURRENT_AMOUNT
            ? \App\Support\ProPricing::formatDisplay(\App\Support\ProPricing::DISPLAY_CURRENT)
            : \App\Support\ProPricing::formatAmount($amount);

        return [
            'name' => (string) config('payments.pro.name'),
            'description' => trim((string) config('payments.pro.description') . ' Профіль: ' . $profile->name . '.'),
            // На тестових сумах показуємо реальну гривневу ціну, щоб UI збігався зі списанням.
            'price_display' => $priceDisplay,
            'price_uah' => \App\Support\ProPricing::formatAmount($amount),
            'amount' => $amount,
            'currency' => (string) config('payments.pro.currency'),
            'success_url' => route('pro.account', ['profile' => $profile->id]),
        ];
    }

    private function ensureMonopayAvailable(Request $request, Profile $profile): RedirectResponse|JsonResponse|null
    {
        if ((string) config('payments.monopay.token') === '') {
            return $this->monopayPaymentFailureResponse(
                $request,
                $profile,
                'pro-billing-provider-unavailable',
                'Оплата monopay тимчасово недоступна: платіжний ключ не налаштований на сервері.'
            );
        }

        if ((int) config('payments.pro.amount') <= 0) {
            return $this->monopayPaymentFailureResponse(
                $request,
                $profile,
                'pro-billing-invalid-amount',
                'Сума PRO-підписки налаштована некоректно. Зверніться в підтримку.'
            );
        }

        return null;
    }

    private function monopayPaymentFailureResponse(
        Request $request,
        Profile $profile,
        string $status,
        string $message,
    ): RedirectResponse|JsonResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'error',
                'message' => $message,
                'redirect_url' => route('pro.account.billing.pay', $profile),
            ], 422);
        }

        return redirect()
            ->route('pro.account.billing.pay', $profile)
            ->with('status', $status);
    }

    private function monopayRedirectUrl(Request $request, array $invoice): string
    {
        $pageUrl = (string) ($invoice['pageUrl'] ?? '');
        $appUrl = (string) ($invoice['appUrl'] ?? '');

        if ($appUrl !== '' && $this->isMobileRequest($request)) {
            return $appUrl;
        }

        return $pageUrl !== '' ? $pageUrl : $appUrl;
    }

    private function isMobileRequest(Request $request): bool
    {
        $userAgent = strtolower((string) $request->userAgent());

        return str_contains($userAgent, 'iphone')
            || str_contains($userAgent, 'ipad')
            || str_contains($userAgent, 'android')
            || str_contains($userAgent, 'mobile');
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $aiSuggestedData
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function mergeOwnerProfileData(array $aiSuggestedData, array $validated): array
    {
        $ownerProfile = $this->resolveOwnerProfileData($aiSuggestedData);

        $ownerProfile['experience_years'] = isset($validated['owner_profile_experience_years']) && $validated['owner_profile_experience_years'] !== null
            ? (int) $validated['owner_profile_experience_years']
            : null;
        $ownerProfile['consultations_count'] = isset($validated['owner_profile_consultations_count']) && $validated['owner_profile_consultations_count'] !== null
            ? (int) $validated['owner_profile_consultations_count']
            : null;
        $ownerProfile['response_speed'] = $this->nullableString($validated['owner_profile_response_speed'] ?? null);
        $ownerProfile['experience'] = $this->nullableString($validated['owner_profile_experience'] ?? null);
        $ownerProfile['faq'] = $this->normalizeOwnerProfileFaq($validated['owner_profile_faq_json'] ?? null);

        $ownerProfile = collect($ownerProfile)
            ->reject(fn ($value) => $this->isBlankOwnerProfileValue($value))
            ->all();

        if ($ownerProfile === []) {
            unset($aiSuggestedData['owner_profile']);

            return $aiSuggestedData;
        }

        $aiSuggestedData['owner_profile'] = $ownerProfile;

        return $aiSuggestedData;
    }

    /**
     * @param  array<string, mixed>  $aiSuggestedData
     * @return array<string, mixed>
     */
    private function resolveOwnerProfileData(array $aiSuggestedData): array
    {
        $ownerProfile = data_get($aiSuggestedData, 'owner_profile');
        $ownerProfile = is_array($ownerProfile) ? $ownerProfile : [];
        $keys = [
            'experience_years',
            'consultations_count',
            'response_speed',
            'experience',
            'faq',
        ];

        foreach ($keys as $key) {
            if ($this->isBlankOwnerProfileValue($ownerProfile[$key] ?? null) && array_key_exists($key, $aiSuggestedData)) {
                $ownerProfile[$key] = $aiSuggestedData[$key];
            }
        }

        return [
            'experience_years' => isset($ownerProfile['experience_years']) && $ownerProfile['experience_years'] !== null
                ? (int) $ownerProfile['experience_years']
                : null,
            'consultations_count' => isset($ownerProfile['consultations_count']) && $ownerProfile['consultations_count'] !== null
                ? (int) $ownerProfile['consultations_count']
                : null,
            'response_speed' => $this->nullableString(is_string($ownerProfile['response_speed'] ?? null) ? $ownerProfile['response_speed'] : null),
            'experience' => $this->nullableString(is_string($ownerProfile['experience'] ?? null) ? $ownerProfile['experience'] : null),
            'faq' => $this->normalizeOwnerProfileFaq($ownerProfile['faq'] ?? []),
        ];
    }

    private function isBlankOwnerProfileValue(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        return $value === null;
    }

    /**
     * @return array<int, array{title:string,text:string}>
     */
    /**
     * @return array<int, array{q:string,a:string}>
     */
    private function normalizeOwnerProfileFaq(mixed $value): array
    {
        $items = $this->decodeOwnerProfileItems($value);

        return collect($items)
            ->map(function ($item): ?array {
                if (! is_array($item)) {
                    return null;
                }

                $question = $this->nullableString(isset($item['q']) ? (string) $item['q'] : (isset($item['question']) ? (string) $item['question'] : null));
                $answer = $this->nullableString(isset($item['a']) ? (string) $item['a'] : (isset($item['answer']) ? (string) $item['answer'] : null));

                if (! $question || ! $answer) {
                    return null;
                }

                return [
                    'q' => $question,
                    'a' => $answer,
                ];
            })
            ->filter()
            ->take(20)
            ->values()
            ->all();
    }

    /**
     * @return array<int, mixed>
     */
    private function decodeOwnerProfileItems(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        $rawValue = trim((string) $value);
        if ($rawValue === '') {
            return [];
        }

        $decoded = json_decode($rawValue, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded)
            ? array_values($decoded)
            : [];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeGalleryEntries(?string $value): array
    {
        $rawValue = trim((string) $value);

        if ($rawValue === '') {
            return [];
        }

        $decoded = json_decode($rawValue, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return collect($decoded)
                ->map(function ($item): ?array {
                    if (is_array($item)) {
                        $url = trim((string) ($item['url'] ?? $item['raw'] ?? ''));
                        if ($url === '') {
                            return null;
                        }

                        return [
                            'url' => $url,
                            'visible' => ! array_key_exists('visible', $item) || (bool) $item['visible'],
                            'title' => trim((string) ($item['title'] ?? '')),
                        ];
                    }

                    $url = trim((string) $item);

                    return $url === '' ? null : [
                        'url' => $url,
                        'visible' => true,
                        'title' => '',
                    ];
                })
                ->filter()
                ->values()
                ->all();
        }

        return collect(preg_split('/\r\n|\r|\n/', $rawValue) ?: [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->map(fn ($url) => ['url' => $url, 'visible' => true, 'title' => ''])
            ->values()
            ->all();
    }

    /**
     * @param  mixed  $items
     * @return array<int, array{url:string,visible:bool,title:string}>
     */
    private function normalizeStoredGalleryEntries(mixed $items): array
    {
        return collect(is_array($items) ? $items : [])
            ->map(function ($item): ?array {
                if (is_array($item)) {
                    $url = trim((string) ($item['url'] ?? $item['raw'] ?? ''));
                    if ($url === '') {
                        return null;
                    }

                    return [
                        'url' => $url,
                        'visible' => ! array_key_exists('visible', $item) || (bool) $item['visible'],
                        'title' => trim((string) ($item['title'] ?? '')),
                    ];
                }

                $url = trim((string) $item);

                return $url === '' ? null : [
                    'url' => $url,
                    'visible' => true,
                    'title' => '',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, UploadedFile>|mixed  $files
     * @return array<int, array{url:string,visible:bool,title:string}>
     */
    private function storeGalleryFiles(Profile $profile, mixed $files): array
    {
        return collect(is_array($files) ? $files : [])
            ->filter(fn ($file) => $file instanceof UploadedFile)
            ->map(function (UploadedFile $file) use ($profile): ?array {
                $stored = Storage::disk('public')->putFile($this->profileMediaDirectory($profile, 'gallery'), $file);

                if (! filled($stored)) {
                    return null;
                }

                return [
                    'url' => $stored,
                    'visible' => true,
                    'title' => '',
                ];
            })
            ->filter(fn ($item) => is_array($item) && filled($item['url'] ?? null))
            ->values()
            ->all();
    }

    private function storeLogoFile(Profile $profile, mixed $file): ?string
    {
        if (! $file instanceof UploadedFile) {
            return null;
        }

        $stored = Storage::disk('public')->putFile($this->profileMediaDirectory($profile, 'logo'), $file);

        return filled($stored) ? $stored : null;
    }

    /**
     * @param  array<int, string>  $previousGallery
     * @param  array<int, string>  $currentGallery
     */
    private function deleteRemovedGalleryFiles(array $previousGallery, array $currentGallery): void
    {
        $removedPaths = collect($previousGallery)
            ->diff($currentGallery)
            ->filter(fn ($path) => $this->isManagedMediaPath((string) $path))
            ->values()
            ->all();

        if ($removedPaths === []) {
            return;
        }

        Storage::disk('public')->delete($removedPaths);
    }

    /**
     * @param  array<int, string>  $activePaths
     */
    private function deleteStaleManagedMediaPath(string $previousPath, array $activePaths): void
    {
        $previousPath = trim($previousPath);

        if (! $this->isManagedMediaPath($previousPath)) {
            return;
        }

        $isStillUsed = collect($activePaths)
            ->map(fn ($path) => trim((string) $path))
            ->contains($previousPath);

        if ($isStillUsed) {
            return;
        }

        Storage::disk('public')->delete($previousPath);
    }

    private function profileMediaDirectory(Profile $profile, string $collection): string
    {
        return sprintf('profiles/%d/%s', $profile->id, trim($collection, '/'));
    }

    private function isManagedMediaPath(string $path): bool
    {
        $normalizedPath = trim($path);
        if ($normalizedPath === '') {
            return false;
        }

        if (Str::startsWith($normalizedPath, ['http://', 'https://', '//', '/'])) {
            return false;
        }

        return Str::startsWith($normalizedPath, 'profiles/');
    }

    private function normalizeSocialLinks(array $existing, array $submitted): array
    {
        $normalized = collect($existing)
            ->mapWithKeys(fn ($value, $key) => [(string) $key => $this->nullableString(is_string($value) ? $value : null)])
            ->filter();

        foreach ($submitted as $key => $value) {
            $key = (string) $key;
            $normalizedValue = $this->nullableString(is_string($value) ? $value : null);

            if ($normalizedValue === null) {
                $normalized->forget($key);
                continue;
            }

            $normalized->put($key, $normalizedValue);
        }

        return $normalized->all();
    }

    /**
     * @param  array<int, mixed>  $names
     * @return array<int, string>
     */
    private function normalizeServiceNames(array $names): array
    {
        return collect($names)
            ->map(fn ($name) => preg_replace('/\s+/u', ' ', trim((string) $name)))
            ->filter()
            ->unique(fn ($name) => mb_strtolower((string) $name))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $existingIds
     * @param  array<int, string>  $serviceNames
     * @return array<int, int>
     */
    private function resolveProfileServiceIds(int $categoryId, array $existingIds, array $serviceNames): array
    {
        $resolvedIds = collect($existingIds);
        $knownServices = CategoryService::query()
            ->where('category_id', $categoryId)
            ->get(['id', 'name', 'slug']);

        foreach ($serviceNames as $serviceName) {
            $normalizedServiceName = mb_strtolower($serviceName);
            $existingService = $knownServices->first(
                fn (CategoryService $service) => mb_strtolower((string) $service->name) === $normalizedServiceName
            );

            if ($existingService) {
                $resolvedIds->push((int) $existingService->id);
                continue;
            }

            $service = CategoryService::query()->create([
                'category_id' => $categoryId,
                'name' => $serviceName,
                'slug' => $this->makeUniqueCategoryServiceSlug($categoryId, $serviceName),
                'is_active' => true,
                'show_in_catalog' => false,
                'sort_order' => 0,
            ]);

            $knownServices->push($service);
            $resolvedIds->push((int) $service->id);
        }

        return $resolvedIds
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function makeUniqueCategoryServiceSlug(int $categoryId, string $serviceName): string
    {
        $baseSlug = Str::slug($serviceName);
        $baseSlug = $baseSlug !== '' ? $baseSlug : 'service';
        $slug = $baseSlug;
        $suffix = 2;

        while (CategoryService::query()
            ->where('category_id', $categoryId)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }
}
