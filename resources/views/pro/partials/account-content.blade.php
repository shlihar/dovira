    @php
        $statusMessages = [
            'claim-submitted' => 'Заявку на прив’язку профілю відправлено. Профіль уже публічний на Dovira; після перевірки прав ви зможете оформити підписку на керування.',
            'claim-updated' => 'Заявку оновлено й повторно відправлено на розгляд.',
            'claim-already-owned' => 'Цей профіль уже прив’язаний до вашого акаунта.',
            'pro-profile-created' => 'Профіль створено як чернетку. Активуйте PRO-підписку, щоб заповнити дані й опублікувати сторінку.',
            'pro-profile-updated' => 'Профіль оновлено.',
            'pro-review-reply-saved' => 'Офіційну відповідь збережено.',
            'pro-review-visibility-updated' => 'Видимість відгуку оновлено.',
            'pro-billing-activated' => 'PRO-підписку активовано.',
            'pro-billing-renewed' => 'PRO-підписку оновлено.',
            'pro-billing-canceled' => 'PRO-підписку вимкнено.',
            'pro-billing-already-inactive' => 'Для цього профілю вже немає активної підписки.',
            'pro-auto-renew-on' => 'Автопродовження увімкнено: наступний період спишеться автоматично.',
            'pro-auto-renew-off' => 'Автопродовження вимкнено. Підписка діє до кінця сплаченого періоду.',
        ];
        $tabTitle = match ($tab) {
            'profile' => 'Профіль',
            'reviews' => 'Відгуки',
            'analytics' => 'Аналітика',
            'notifications' => 'Сповіщення',
            'billing' => 'Оплата',
            'claims' => 'Привʼязка профілів',
            default => 'Огляд',
        };
        $siteSupportTelegramUsername = ltrim((string) config('site_contacts.telegram_username', 'dovira_support'), '@');
        $siteSupportTelegramUrl = 'https://t.me/' . $siteSupportTelegramUsername;
        $selectedClaimPrimaryCategory = $selectedClaimProfile?->categories->first();
        $primaryCategory = $currentProfile?->categories->first();
        $selectedCategoryId = (int) old('category_id', $primaryCategory?->parent_id ?: $primaryCategory?->id);
        $selectedSubcategoryId = (int) old('subcategory_id', $primaryCategory?->parent_id ? $primaryCategory?->id : null);
        $selectedRegionId = (int) old('region_id', $currentProfile?->region_id);
        $plainProfileText = static function ($value): string {
            $text = (string) $value;
            $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $text) ?? $text;
            $text = preg_replace('/<\s*\/p\s*>/i', "\n", $text) ?? $text;
            $text = strip_tags($text);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
            $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

            return trim($text);
        };
        $shortDescriptionEditValue = $plainProfileText(old('short_description', $currentProfile?->short_description));
        $descriptionEditValue = $plainProfileText(old('description', $currentProfile?->description));
        $oldServiceNames = old('service_names');
        $normalizeGalleryPreview = static function ($value): \Illuminate\Support\Collection {
            if (is_array($value)) {
                $items = $value;
            } else {
                $rawValue = trim((string) $value);

                if ($rawValue === '') {
                    return collect();
                }

                $decoded = json_decode($rawValue, true);
                $items = json_last_error() === JSON_ERROR_NONE && is_array($decoded)
                    ? $decoded
                    : (preg_split('/\r\n|\r|\n/', $rawValue) ?: []);
            }

            return collect($items)
                ->map(function ($item): ?array {
                    if (is_array($item)) {
                        $rawUrl = trim((string) ($item['url'] ?? $item['raw'] ?? ''));
                        $visible = ! array_key_exists('visible', $item) || (bool) $item['visible'];
                        $title = trim((string) ($item['title'] ?? ''));
                    } else {
                        $rawUrl = trim((string) $item);
                        $visible = true;
                        $title = '';
                    }

                    if ($rawUrl === '') {
                        return null;
                    }

                    $publicUrl = \App\Support\MediaUrl::publicImageUrl($rawUrl);

                    if (! filled($publicUrl)) {
                        return null;
                    }

                    return [
                        'raw' => $rawUrl,
                        'url' => $publicUrl,
                        'visible' => $visible,
                        'title' => $title,
                    ];
                })
                ->filter()
                ->values();
        };
        $galleryUrlsValue = old(
            'gallery_urls',
            json_encode(
                $normalizeGalleryPreview($currentProfile?->gallery ?? [])
                    ->map(fn ($item) => [
                        'url' => $item['raw'],
                        'visible' => $item['visible'],
                        'title' => $item['title'],
                    ])
                    ->values()
                    ->all(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );
        $currentSocialLinks = collect((array) ($currentProfile?->social_links ?? []));
        $socialInputs = [
            'telegram' => old('social_telegram', (string) $currentSocialLinks->get('telegram', '')),
            'facebook' => old('social_facebook', (string) $currentSocialLinks->get('facebook', '')),
            'instagram' => old('social_instagram', (string) $currentSocialLinks->get('instagram', '')),
            'youtube' => old('social_youtube', (string) $currentSocialLinks->get('youtube', '')),
            'viber' => old('social_viber', (string) $currentSocialLinks->get('viber', '')),
            'whatsapp' => old('social_whatsapp', (string) $currentSocialLinks->get('whatsapp', '')),
        ];
        $socialNetworkOptions = [
            'telegram' => ['label' => 'Telegram', 'icon' => 'fa-brands fa-telegram', 'placeholder' => 'https://t.me/...', 'field' => 'social_telegram'],
            'facebook' => ['label' => 'Facebook', 'icon' => 'fa-brands fa-facebook-f', 'placeholder' => 'https://facebook.com/...', 'field' => 'social_facebook'],
            'instagram' => ['label' => 'Instagram', 'icon' => 'fa-brands fa-instagram', 'placeholder' => 'https://instagram.com/...', 'field' => 'social_instagram'],
            'youtube' => ['label' => 'YouTube', 'icon' => 'fa-brands fa-youtube', 'placeholder' => 'https://youtube.com/@...', 'field' => 'social_youtube'],
            'viber' => ['label' => 'Viber', 'icon' => 'fa-brands fa-viber', 'placeholder' => 'https://invite.viber.com/...', 'field' => 'social_viber'],
            'whatsapp' => ['label' => 'WhatsApp', 'icon' => 'fa-brands fa-whatsapp', 'placeholder' => 'https://wa.me/380...', 'field' => 'social_whatsapp'],
        ];
        $proProfileData = collect((array) data_get($currentProfile?->ai_suggested_data, 'owner_profile', []));
        foreach (['experience_years', 'consultations_count', 'response_speed', 'experience', 'faq'] as $ownerProfileKey) {
            if (! $proProfileData->has($ownerProfileKey) && filled(data_get($currentProfile?->ai_suggested_data, $ownerProfileKey))) {
                $proProfileData->put($ownerProfileKey, data_get($currentProfile?->ai_suggested_data, $ownerProfileKey));
            }
        }
        $ownerProfileFaq = collect(old('owner_profile_faq_json') ? (json_decode((string) old('owner_profile_faq_json'), true) ?: []) : (array) $proProfileData->get('faq', []))
            ->filter(fn ($item) => is_array($item))
            ->values();
        $ownerProfileFaqJson = old(
            'owner_profile_faq_json',
            json_encode($ownerProfileFaq->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        // Кейси PRO: «з чим прийшов клієнт → що зробили → результат».
        $ownerProfileCases = collect(
            old('owner_profile_cases_json')
                ? (json_decode((string) old('owner_profile_cases_json'), true) ?: [])
                : \App\Support\ProfileCases::normalize($proProfileData->get('cases', []))
        )->filter(fn ($item) => is_array($item))->values();
        $ownerProfileCasesJson = old(
            'owner_profile_cases_json',
            json_encode($ownerProfileCases->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $claimAlreadyOwnedByUser = $selectedClaimProfile && (int) $selectedClaimProfile->owner_user_id === (int) $user->id;
        $claimManagedByAnotherOwner = $selectedClaimProfile
            && ! $claimAlreadyOwnedByUser
            && ((bool) ($selectedClaimProfile->has_approved_claim ?? false) || filled($selectedClaimProfile->owner_user_id));
        $profileStatusLabel = match ($currentProfile?->status) {
            'active' => 'Опубліковано',
            'hidden' => 'Приховано',
            'draft' => 'Чернетка',
            default => 'Без статусу',
        };
        $completionPercentValue = max(0, min(100, (int) ($completionPercent ?? 0)));
        $hasMultipleOwnedProfiles = $ownedProfiles->count() > 1;
        $analyticsPeriodOption = collect($analyticsPeriodOptions ?? [])
            ->firstWhere('key', $analyticsPeriod)
            ?? ['key' => 'last_30', 'label' => 'Останні 30 днів', 'short_label' => 'За 30 днів'];
        $analyticsPeriodShortLabel = (string) data_get($analytics ?? [], 'period.short_label', $analyticsPeriodOption['short_label'] ?? 'За 30 днів');
        $analyticsPeriodContextLabel = mb_strtolower($analyticsPeriodShortLabel);
        $chartPeriodFrom = data_get($analytics ?? [], 'period.from');
        $chartDaysCount = max(1, (int) data_get($analytics ?? [], 'period.days_count', match ($analyticsPeriod) {
            'today' => 1,
            'last_7' => 7,
            'last_90' => 90,
            default => 30,
        }));
        $viewTrend = collect(data_get($analytics ?? [], 'metrics.views.sparkline', []))
            ->map(fn ($value) => (int) $value)
            ->values();

        if ($viewTrend->isEmpty()) {
            $viewTrend = collect(array_fill(0, $chartDaysCount, 0));
        }

        $chartHasMeaningfulData = $viewTrend->max() > 0;
        $chartStartDate = $chartPeriodFrom instanceof \Carbon\CarbonInterface
            ? $chartPeriodFrom->copy()
            : now()->subDays(max(0, $viewTrend->count() - 1));
        $chartPayload = [
            'period' => $analyticsPeriod,
            'periodLabel' => $analyticsPeriodShortLabel,
            'startDate' => $chartStartDate->toDateString(),
            'values' => $viewTrend->all(),
            'total' => (int) data_get($analytics ?? [], 'metrics.views.current', 0),
            'trendPercent' => (float) data_get($analytics ?? [], 'metrics.views.diff_percent', 0),
            'trendUp' => (bool) data_get($analytics ?? [], 'metrics.views.is_up', true),
        ];
        $normalizeAnalyticsSeries = static function ($values, int $length): array {
            $series = collect((array) $values)
                ->map(fn ($value) => (int) $value)
                ->values()
                ->all();

            return array_slice(array_pad($series, $length, 0), 0, $length);
        };
        $analyticsChartLabels = collect(range(0, max(0, $chartDaysCount - 1)))
            ->map(fn ($offset) => $chartStartDate->copy()->addDays($offset)->format('d.m'))
            ->all();
        $contactTrend = $normalizeAnalyticsSeries(data_get($analytics ?? [], 'metrics.contact_clicks.sparkline', []), $chartDaysCount);
        $websiteTrend = $normalizeAnalyticsSeries(data_get($analytics ?? [], 'metrics.website_clicks.sparkline', []), $chartDaysCount);
        $analyticsTrafficChartPayload = [
            'labels' => $analyticsChartLabels,
            'datasets' => [
                [
                    'type' => 'line',
                    'label' => 'Перегляди профілю',
                    'values' => $viewTrend->all(),
                    'color' => '#2f6df6',
                    'fill' => true,
                ],
                [
                    'type' => 'line',
                    'label' => 'Кліки на контакти',
                    'values' => $contactTrend,
                    'color' => '#2fbf74',
                    'fill' => false,
                ],
            ],
        ];
        $analyticsReviewsChartPayload = [
            'labels' => $reviewsRatingTimeline['labels'] ?? [],
            'datasets' => [
                [
                    'type' => 'bar',
                    'label' => 'Нові відгуки',
                    'values' => $reviewsRatingTimeline['review_counts'] ?? [],
                    'color' => '#9b7cf6',
                    'yAxisID' => 'y',
                ],
                [
                    'type' => 'line',
                    'label' => 'Середній рейтинг',
                    'values' => $reviewsRatingTimeline['rating_values'] ?? [],
                    'color' => '#f5a300',
                    'yAxisID' => 'y1',
                    'fill' => false,
                ],
            ],
        ];
        $analyticsTotalClicks = (int) data_get($analytics ?? [], 'contact_clicks', 0) + (int) data_get($analytics ?? [], 'website_clicks', 0);
        // Головна цифра підписки: скільки разів картку показали саме на платних
        // позиціях каталогу. Це прямий результат оплати, тому картку бачать
        // лише підписники — у решти вона завжди нульова.
        $promotedImpressions = (int) data_get($analytics ?? [], 'metrics.promoted_impressions.current', 0);
        $showsPromotedMetric = (bool) $currentProfile?->hasActiveProSubscription();
        /** @var array<int, array<string, mixed>> $analyticsMetricCards */
        $analyticsMetricCards = [
            [
                'icon' => 'fa-regular fa-eye',
                'tone' => 'blue',
                'value' => number_format((int) data_get($analytics ?? [], 'metrics.views.current', 0), 0, '.', ' '),
                'label' => 'Перегляди профілю',
                'trend' => $trendDisplays['views'] ?? null,
            ],
            [
                'icon' => 'fa-regular fa-rectangle-list',
                'tone' => 'blue',
                'value' => number_format((int) data_get($analytics ?? [], 'metrics.impressions.current', 0), 0, '.', ' '),
                'label' => 'Покази картки',
                'trend' => $trendDisplays['impressions'] ?? null,
            ],
            [
                'icon' => 'fa-solid fa-arrow-trend-up',
                'tone' => 'gold',
                'value' => number_format($promotedImpressions, 0, '.', ' '),
                'label' => 'Показів на платних позиціях',
                'trend' => $promotedImpressions > 0 ? ($trendDisplays['promoted_impressions'] ?? null) : null,
                // Окремий лічильник запущено 22.09.2026 — поки цифра нульова,
                // пояснюємо це, щоб нуль не читався як «підписка не працює».
                'hint' => $promotedImpressions > 0 ? null : 'Рахуємо з 22.09.2026',
                'only_pro' => true,
            ],
            [
                'icon' => 'fa-solid fa-phone',
                'tone' => 'green',
                'value' => number_format((int) data_get($analytics ?? [], 'metrics.contact_clicks.current', 0), 0, '.', ' '),
                'label' => 'Кліки на контакти',
                'trend' => $trendDisplays['contact_clicks'] ?? null,
            ],
            [
                'icon' => 'fa-solid fa-globe',
                'tone' => 'violet',
                'value' => number_format((int) data_get($analytics ?? [], 'metrics.website_clicks.current', 0), 0, '.', ' '),
                'label' => 'Кліки на сайт',
                'trend' => $trendDisplays['website_clicks'] ?? null,
            ],
            [
                'icon' => 'fa-solid fa-star',
                'tone' => 'gold',
                'value' => number_format((int) ($reviewsMetric['current'] ?? 0), 0, '.', ' '),
                'label' => 'Нові відгуки',
                'trend' => $trendDisplays['reviews'] ?? null,
            ],
            [
                'icon' => 'fa-solid fa-shield-halved',
                'tone' => 'orange',
                'value' => number_format((float) ($currentProfile?->rating_avg ?? 0), 1),
                'label' => 'Середній рейтинг',
                'trend' => null,
                'suffix' => '/5',
            ],
        ];
        if (! $showsPromotedMetric) {
            $analyticsMetricCards = array_values(array_filter(
                $analyticsMetricCards,
                static fn (array $metric): bool => empty($metric['only_pro'])
            ));
        }
        $analyticsSources = collect($sources ?? [])->values();
        $analyticsSourcesTotal = (int) $analyticsSources->sum('value');
        // Пункти заповнення — лише те, що власник заповнює редагуванням
        // (без відгуків/slug/SEO), без дублів. Відсоток кільця рахуємо з
        // цих самих пунктів, тож галочки й % завжди узгоджені.
        $completionItems = collect([
            ['label' => 'Основна інформація', 'done' => filled($currentProfile?->name) && filled($currentProfile?->description)],
            ['label' => 'Контакти', 'done' => filled($currentProfile?->phone) || filled($currentProfile?->email) || filled($currentProfile?->website)],
            ['label' => 'Локація', 'done' => filled($currentProfile?->city)],
            ['label' => 'Послуги', 'done' => ($currentProfile?->services->count() ?? 0) > 0],
            ['label' => 'Логотип', 'done' => filled($currentProfile?->logo_url)],
            ['label' => 'Фото та галерея', 'done' => ! empty($currentProfile?->gallery)],
        ]);
        $completionDoneCount = $completionItems->where('done', true)->count();
        $completionPercentValue = $completionItems->count() > 0
            ? (int) round($completionDoneCount / $completionItems->count() * 100)
            : 0;
        // The builder emits only real, actionable problems (sorted by
        // severity, max 4) — no extra filtering needed here.
        $overviewAttentionItems = collect($actionItems)->values();
        $subscriptionIsActive = ($latestSubscription && $latestSubscription->status === 'active' && (! $latestSubscription->ends_at || $latestSubscription->ends_at->isFuture()))
            || (($currentProfile?->is_pro ?? false) && ($currentProfile?->status === 'active'));
        $subscriptionEndLabel = $latestSubscription?->ends_at?->translatedFormat('d F Y');
        $subscriptionStatus = (string) ($latestSubscription?->status ?? '');
        $daysToSubscriptionEnd = $latestSubscription?->ends_at ? now()->diffInDays($latestSubscription->ends_at, false) : null;
        $hasPublishedProfile = (bool) ($currentProfile?->show_in_catalog ?? false)
            && (($currentProfile?->status ?? '') === 'active')
            && (($currentProfile?->is_published ?? true));
        $hasOwnerVerification = (bool) ($currentProfile?->is_owner_verified ?? false)
            || (bool) ($currentProfile?->has_approved_claim ?? false);
        $claimStatus = (string) ($latestClaim?->status ?? '');
        $unansweredReviewsCount = (int) ($reviewStatusSummary['without_reply'] ?? 0);
        $hasBillingIssue = in_array($subscriptionStatus, ['payment_failed', 'past_due', 'unpaid'], true);
        $hasExpiredSubscription = in_array($subscriptionStatus, ['expired', 'canceled'], true)
            || ($daysToSubscriptionEnd !== null && $daysToSubscriptionEnd < 0);
        $subscriptionEndingSoon = $subscriptionIsActive && $daysToSubscriptionEnd !== null && $daysToSubscriptionEnd <= 14;
        $prioritySearchEnabled = $subscriptionIsActive && $hasPublishedProfile;
        $configuredProAmount = (int) config('payments.pro.amount');
        $currentProDiscount = $currentProfile
            ? \App\Support\ProDiscount::price($currentProfile, $configuredProAmount)
            : \App\Support\ProDiscount::price(new \App\Models\Profile, $configuredProAmount);
        $currentProDisplayDiscount = $currentProfile
            ? \App\Support\ProDiscount::price($currentProfile, \App\Support\ProPricing::DISPLAY_CURRENT)
            : \App\Support\ProDiscount::price(new \App\Models\Profile, \App\Support\ProPricing::DISPLAY_CURRENT);
        $currentProBasePriceDisplay = \App\Support\ProPricing::formatDisplay(\App\Support\ProPricing::DISPLAY_CURRENT);
        $currentProPriceDisplay = \App\Support\ProPricing::formatDisplay((int) $currentProDisplayDiscount['amount']);
        $showProUpgradePopup = $currentProfile && $hasOwnerVerification && ! $subscriptionIsActive;

        // «Цифри, що продають» для CTA підписки: показуємо лише коли їх
        // достатньо (правило: PRO пропонуємо, коли цифри говорять самі) —
        // інакше CTA лишається без цифрового рядка.
        $sellViews = (int) data_get($analytics ?? [], 'metrics.views.current', 0);
        $sellSeen = $sellViews + (int) data_get($analytics ?? [], 'metrics.impressions.current', 0);
        $sellClicks = (int) data_get($analytics ?? [], 'metrics.contact_clicks.current', 0)
            + (int) data_get($analytics ?? [], 'metrics.website_clicks.current', 0);
        $sellPeriodLabel = mb_strtolower((string) data_get($analytics ?? [], 'period.short_label', 'за 30 днів'));
        $sellNumbersLine = $sellSeen >= 20
            ? ('Вас побачили ' . number_format($sellSeen, 0, '.', ' ') . ' разів ' . $sellPeriodLabel
                . ', ' . number_format($sellViews, 0, '.', ' ') . ' відкрили профіль'
                . ($sellClicks > 0 ? ', ' . number_format($sellClicks, 0, '.', ' ') . ' натиснули контакти чи сайт' : '')
                . '.')
            : null;

        $subscriptionSummary = [
            'title' => 'Підписка активна',
            'text' => $subscriptionEndLabel
                ? 'Дійсна до ' . $subscriptionEndLabel
                : 'Дійсна для цього профілю',
            'dot_class' => 'is-success',
        ];

        $sidebarStatusItems = [
            [
                'icon' => 'fa-circle-exclamation',
                'label' => 'Публічний профіль',
                'state' => $hasPublishedProfile ? 'success' : 'warning',
            ],
            [
                'icon' => 'fa-magnifying-glass',
                'label' => 'Пріоритет у пошуку',
                'state' => $prioritySearchEnabled ? 'success' : ($subscriptionIsActive ? 'info' : 'muted'),
            ],
            [
                'icon' => 'fa-chart-column',
                'label' => 'Розширена аналітика',
                'state' => $subscriptionIsActive ? 'success' : 'muted',
            ],
            [
                'icon' => 'fa-star',
                'label' => 'Підтримка PRO',
                'state' => ! $subscriptionIsActive ? 'muted' : ($unansweredReviewsCount > 0 ? 'warning' : 'success'),
            ],
        ];
        $overviewMetricCards = [
            [
                'icon' => 'fa-regular fa-eye',
                'tone' => 'blue',
                'value' => number_format((int) data_get($analytics ?? [], 'metrics.views.current', 0), 0, '.', ' '),
                'label' => 'Перегляди профілю',
                'trend' => $trendDisplays['views'] ?? null,
                'subtext' => $analyticsPeriodContextLabel,
            ],
            [
                'icon' => 'fa-regular fa-hand-pointer',
                'tone' => 'green',
                'value' => number_format((int) data_get($analytics ?? [], 'contact_clicks', 0), 0, '.', ' '),
                'label' => 'Кліки на контакти',
                'trend' => $trendDisplays['contact_clicks'] ?? null,
                'subtext' => $analyticsPeriodContextLabel,
            ],
            [
                'icon' => 'fa-solid fa-star',
                'tone' => 'violet',
                'value' => number_format((int) ($reviewsMetric['current'] ?? 0), 0, '.', ' '),
                'label' => 'Нові відгуки',
                'trend' => $trendDisplays['reviews'] ?? null,
                'subtext' => $analyticsPeriodContextLabel,
            ],
            [
                'icon' => 'fa-solid fa-star',
                'tone' => 'gold',
                'value' => number_format((float) ($currentProfile?->rating_avg ?? 0), 1),
                'label' => 'Середній рейтинг',
                'trend' => null,
                'trend_up' => true,
                'subtext' => 'на основі ' . (int) ($publishedReviewsCount ?? 0) . ' відгуків',
            ],
        ];
        if (! ($canViewAnalytics ?? false)) {
            $overviewMetricCards = collect($overviewMetricCards)
                ->reject(fn ($metric) => in_array($metric['label'], ['Перегляди профілю', 'Кліки на контакти'], true))
                ->values()
                ->all();
        }
        $logo = trim((string) ($currentProfile->logo_url ?? ''));
        $logoUrl = \App\Support\MediaUrl::publicImageUrl($logo);
        $profileAvatarInitial = mb_strtoupper(mb_substr(trim((string) ($currentProfile?->name ?? '')), 0, 1)) ?: 'П';
        $selectedRootCategory = $categories->firstWhere('id', (int) $selectedCategoryId);
        $selectedSubcategory = collect($categoryChildren[$selectedCategoryId] ?? [])
            ->firstWhere('id', (int) $selectedSubcategoryId);
        $selectedSubcategoryName = is_array($selectedSubcategory) ? ($selectedSubcategory['name'] ?? null) : null;
        $selectedCategoryName = $selectedSubcategoryName
            ?? $selectedRootCategory?->name
            ?? $primaryCategory?->name
            ?? 'Без категорії';
        $selectedRegionName = $regions->firstWhere('id', (int) $selectedRegionId)?->name
            ?? $currentProfile?->region?->name
            ?? null;
        $profileSectionEditClass = $errors->any() ? ' is-editing' : '';
        $previewLogoRaw = old('logo_url', $currentProfile?->logo_url);
        $previewLogoUrl = \App\Support\MediaUrl::publicImageUrl($previewLogoRaw);
        $previewBannerUrl = \App\Support\MediaUrl::publicImageUrl(old('banner_url', $currentProfile?->banner_url));
        $previewPublicUrl = $currentProfile?->slug ? route('profile.show', ['slug' => $currentProfile->slug]) : null;
        $previewWebsiteHref = \App\Support\WebsiteUrl::href(old('website', $currentProfile?->website));
        $previewWebsiteDisplay = \App\Support\WebsiteUrl::display(old('website', $currentProfile?->website));
        $previewSummary = trim((string) old('short_description', $currentProfile?->short_description ?: $currentProfile?->description));
        $previewSummary = $previewSummary !== '' ? \Illuminate\Support\Str::limit($previewSummary, 220) : '';
        $profileDescriptionDraft = (string) old('short_description', $currentProfile?->short_description);
        $profileDescriptionLength = mb_strlen($profileDescriptionDraft);
        $selectedServiceNames = collect(is_array($oldServiceNames) ? $oldServiceNames : ($currentProfile?->services->pluck('name')->all() ?? []))
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique(fn ($name) => mb_strtolower($name))
            ->values();
        $resolvedSeoTitle = \App\Support\ProfileSeo::resolvedTitle(
            $currentProfile?->seo_title,
            old('name', $currentProfile?->name),
            $selectedCategoryName,
            old('city', $currentProfile?->city)
        );
        $resolvedSeoDescription = \App\Support\ProfileSeo::resolvedDescription(
            $currentProfile?->seo_description,
            old('name', $currentProfile?->name),
            $selectedCategoryName,
            old('city', $currentProfile?->city),
            $selectedServiceNames->all(),
            old('short_description', $currentProfile?->short_description),
            old('description', $currentProfile?->description)
        );
        $activeSocialLinks = collect($socialInputs)->filter(fn ($value) => filled($value));
        $previewGalleryItems = $normalizeGalleryPreview($galleryUrlsValue);
        $previewChecklistItems = collect([
            ['label' => 'Заповніть основну інформацію', 'done' => filled(old('name', $currentProfile?->name)) && $selectedCategoryId > 0 && filled(old('city', $currentProfile?->city))],
            ['label' => 'Додайте контакти', 'done' => filled(old('phone', $currentProfile?->phone)) && filled(old('email', $currentProfile?->email))],
            ['label' => 'Опишіть послуги', 'done' => $selectedServiceNames->isNotEmpty()],
            ['label' => 'Додайте фото та логотип', 'done' => filled($previewLogoRaw) && $previewGalleryItems->isNotEmpty()],
            ['label' => 'Опублікуйте профіль', 'done' => (($currentProfile?->status ?? '') === 'active') && (bool) ($currentProfile?->show_in_catalog ?? false)],
        ]);
        $profileSidebarStatusItems = [
            [
                'icon' => 'fa-id-card',
                'label' => 'Основна інформація',
                'state' => filled(old('name', $currentProfile?->name)) && filled(old('short_description', $currentProfile?->short_description)) ? 'success' : 'muted',
            ],
            [
                'icon' => 'fa-address-book',
                'label' => 'Контакти',
                'state' => filled(old('phone', $currentProfile?->phone)) || filled(old('email', $currentProfile?->email)) || filled(old('website', $currentProfile?->website)) ? 'success' : 'muted',
            ],
            [
                'icon' => 'fa-briefcase',
                'label' => 'Послуги',
                'state' => $selectedServiceNames->isNotEmpty() ? 'success' : 'muted',
            ],
            [
                'icon' => 'fa-share-nodes',
                'label' => 'Соцмережі',
                'state' => $activeSocialLinks->isNotEmpty() ? 'success' : 'muted',
            ],
            [
                'icon' => 'fa-image',
                'label' => 'Медіа',
                'state' => filled($previewLogoRaw) || $previewGalleryItems->isNotEmpty() ? 'success' : 'muted',
            ],
        ];
    @endphp

    <section class="account-shell section" data-pro-account-shell data-pro-account-livewire="1" data-pro-initial-tab="{{ $tab }}">
        @if ($showProUpgradePopup)
            <div
                class="pro-upgrade-popup"
                data-pro-upgrade-popup
                data-profile-id="{{ $currentProfile->id }}"
                hidden
            >
                <div class="pro-upgrade-popup__backdrop" data-pro-upgrade-popup-close></div>
                <section class="pro-upgrade-popup__dialog" role="dialog" aria-modal="true" aria-labelledby="pro-upgrade-popup-title">
                    <button type="button" class="pro-upgrade-popup__close" data-pro-upgrade-popup-close aria-label="Закрити">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                    <span class="pro-upgrade-popup__badge">Профіль підтверджено</span>
                    @if ($sellNumbersLine)
                        <h2 id="pro-upgrade-popup-title">{{ $sellNumbersLine }}</h2>
                        <p>
                            Ці люди вже дивляться ваш профіль — але керування ще закрите: заявки без контактів,
                            офіційні відповіді на відгуки недоступні, сторінку не відредагувати.
                        </p>
                    @else
                        <h2 id="pro-upgrade-popup-title">Без підписки профіль не працює на повну</h2>
                        <p>
                            Ви вже підтвердили права. Тепер профіль ваш, але повне керування ще закрите:
                            заявки без контактів, офіційні відповіді на відгуки недоступні, сторінку не відредагувати.
                        </p>
                    @endif
                    <p>
                        {{ \App\Support\ProPricing::launchCohortPriceLine() }} {{ \App\Support\ProPricing::launchCohortLockLine() }}
                        {{ \App\Support\ProPricing::launchCohortCounterLine() }}
                    </p>
                    <ul>
                        <li>Відкрийте контакти клієнтів у заявках і детальну аналітику.</li>
                        <li>Додайте послуги, ціни та фото — профіль стане вітриною.</li>
                        <li>Відповідайте на відгуки від імені компанії.</li>
                        <li>Розмістіть бейдж DOVIRA на сайті або в соцмережах і отримайте знижку 20%.</li>
                    </ul>
                    <div class="pro-upgrade-popup__actions">
                        <a class="btn btn--primary" href="{{ route('pro.account.billing.pay', $currentProfile) }}">
                            <span>Оформити підписку зараз</span>
                            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                        </a>
                        <button type="button" class="btn btn--ghost" data-pro-upgrade-popup-close>Пізніше</button>
                    </div>
                </section>
            </div>
        @endif

        <div class="container account-layout">
            <div class="pro-account-breadcrumbs" data-pro-tab-visible="overview,reviews,analytics,notifications,billing,claims" @if ($tab === 'profile') hidden @endif>
                    <a href="{{ route('pro.account') }}">PRO кабінет</a>
                    <span>›</span>
                    <span data-pro-tab-title>{{ $tabTitle }}</span>
                </div>

            <div class="account-mobile-overlay" data-account-menu-overlay></div>

            <aside class="account-sidebar card" data-account-sidebar id="account-sidebar">
                <h1 class="account-sidebar__title">PRO кабінет</h1>
                <button class="account-sidebar__close" type="button" aria-label="Закрити меню" data-account-menu-close>
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>

                @if ($hasMultipleOwnedProfiles)
                    <div class="pro-account-selector">
                        <p class="pro-account-selector__label">Ваші профілі</p>
                        <div class="pro-account-selector__list">
                            @foreach ($ownedProfiles as $profileOption)
                                {{-- Посилання на ?profile={id} (той самий патерн, що на
                                     мобільному) — перемикання на mount через #[Url], без
                                     залежності від wire:click. --}}
                                <a
                                    class="pro-account-selector__item {{ $currentProfile && $currentProfile->id === $profileOption->id ? 'is-active' : '' }}"
                                    @if ($currentProfile && $currentProfile->id === $profileOption->id) aria-current="true" @endif
                                    href="{{ route('pro.account', ['profile' => $profileOption->id, 'tab' => $tab]) }}"
                                >
                                    <strong>{{ $profileOption->name }}</strong>
                                    <small>{{ $profileOption->city ?: ($profileOption->region?->name ?: 'Без локації') }}</small>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                <nav class="account-nav pro-account-nav" role="tablist" aria-label="Розділи кабінету">
                    @if ($currentProfile)
                        <button type="button" class="account-nav__item {{ $tab === 'overview' ? 'is-active' : '' }}" data-pro-tab-trigger data-pro-tab-primary role="tab" data-pro-tab-target="overview">
                            <span class="account-nav__icon"><i class="fa-solid fa-gauge-high"></i></span>
                            <span class="account-nav__text">Огляд</span>
                        </button>
                        <button type="button" class="account-nav__item {{ $tab === 'profile' ? 'is-active' : '' }}" data-pro-tab-trigger data-pro-tab-primary role="tab" data-pro-tab-target="profile">
                            <span class="account-nav__icon"><i class="fa-regular fa-user"></i></span>
                            <span class="account-nav__text">Профіль</span>
                        </button>
                        <button type="button" class="account-nav__item {{ $tab === 'reviews' ? 'is-active' : '' }}" data-pro-tab-trigger data-pro-tab-primary role="tab" data-pro-tab-target="reviews">
                            <span class="account-nav__icon"><i class="fa-regular fa-message"></i></span>
                            <span class="account-nav__text">Відгуки</span>
                        </button>
                        @if (($leadsData['available'] ?? false))
                            <button type="button" class="account-nav__item {{ $tab === 'leads' ? 'is-active' : '' }}" data-pro-tab-trigger data-pro-tab-primary role="tab" data-pro-tab-target="leads">
                                <span class="account-nav__icon"><i class="fa-solid fa-envelope"></i></span>
                                <span class="account-nav__text">Заявки</span>
                                @if (($leadsData['unread'] ?? 0) > 0)
                                    <span class="account-nav__badge">{{ $leadsData['unread'] }}</span>
                                @endif
                            </button>
                        @endif
                        @if ($canViewAnalytics ?? false)
                            <button type="button" class="account-nav__item {{ $tab === 'analytics' ? 'is-active' : '' }}" data-pro-tab-trigger data-pro-tab-primary role="tab" data-pro-tab-target="analytics">
                                <span class="account-nav__icon"><i class="fa-solid fa-chart-simple"></i></span>
                                <span class="account-nav__text">Аналітика</span>
                            </button>
                        @endif
                        <button type="button" class="account-nav__item account-nav__item--mobile-nested {{ $tab === 'notifications' ? 'is-active' : '' }}" data-pro-tab-trigger data-pro-tab-primary role="tab" data-pro-tab-target="notifications">
                            <span class="account-nav__icon"><i class="fa-regular fa-bell"></i></span>
                            <span class="account-nav__text">Сповіщення</span>
                            @if (($notificationUnreadCount ?? 0) > 0)
                                <span class="account-nav__badge" data-pro-notifications-nav-badge>{{ min((int) $notificationUnreadCount, 99) }}</span>
                            @endif
                        </button>
                        <button type="button" class="account-nav__item account-nav__item--mobile-nested {{ $tab === 'billing' ? 'is-active' : '' }}" data-pro-tab-trigger data-pro-tab-primary role="tab" data-pro-tab-target="billing">
                            <span class="account-nav__icon"><i class="fa-regular fa-credit-card"></i></span>
                            <span class="account-nav__text">Оплата</span>
                        </button>
                    @else
                        <button type="button" class="account-nav__item is-active" data-pro-tab-trigger data-pro-tab-primary role="tab" data-pro-tab-target="claims">
                            <span class="account-nav__icon"><i class="fa-solid fa-link"></i></span>
                            <span class="account-nav__text">Привʼязка профілів</span>
                        </button>
                    @endif
                </nav>

                @if ($currentProfile)
                    <button type="button" class="pro-account-sidebar-link" data-pro-tab-trigger data-pro-tab-target="claims">
                        <i class="fa-solid fa-link" aria-hidden="true"></i>
                        <span>Привʼязати ще профіль</span>
                    </button>
                @endif

                {{-- Картку статусу «Підписка» прибрано з сайдбару: усе про
                     підписку є на вкладці «Оплата», у сайдбарі це був шум. --}}

                {{-- Сайдбар-картку «Заповнення профілю» прибрано: вона дублювала
                     картку в огляді й рахувала за іншим списком пунктів. Готовність
                     тепер лише в огляді (єдиний узгоджений рахунок). --}}

                @if ($currentProfile)
                    <div class="pro-account-tab-stack" data-pro-tab-visible="profile" @if ($tab !== 'profile') hidden @endif>
                    <section class="pro-profile-help-card">
                        <h2>Потрібна допомога?</h2>
                        <p>Наші фахівці допоможуть вам налаштувати профіль.</p>
                        <a class="btn btn--ghost account-btn--mini" href="{{ $siteSupportTelegramUrl }}" target="_blank" rel="noopener noreferrer">
                            <i class="fa-brands fa-telegram" aria-hidden="true"></i>
                            <span>Зв’язатися з підтримкою</span>
                        </a>
                    </section>
                    </div>
                @endif
            </aside>

            <div class="account-main">
                <div class="account-mobile-toolbar">
                    <button class="account-mobile-menu-btn" type="button" data-account-menu-toggle aria-expanded="false" aria-controls="account-sidebar">
                        <i class="fa-solid fa-bars" aria-hidden="true"></i>
                        <span>Меню</span>
                    </button>
                    <p class="account-mobile-toolbar__title" data-pro-tab-title>{{ $tabTitle }}</p>
                </div>

                {{-- Перемикач профілів для мобільного: на телефоні бічний сайдбар
                     із «Ваші профілі» недоступний (☰-меню приховано), тож коли
                     профілів більше одного — показуємо компактний account-switcher.
                     Розгортання нативне (<details>), вибір — той самий Livewire
                     selectProfile(); після кліку компонент перерендериться і
                     список згорнеться сам. --}}
                @if ($hasMultipleOwnedProfiles)
                    <details class="pro-mobile-switcher">
                        <summary class="pro-mobile-switcher__toggle">
                            <span class="pro-mobile-switcher__avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($currentProfile?->name ?: 'D', 0, 1)) }}</span>
                            <span class="pro-mobile-switcher__meta">
                                <small>Керуєте профілем</small>
                                <strong>{{ $currentProfile?->name ?: 'Оберіть профіль' }}</strong>
                            </span>
                            <i class="fa-solid fa-chevron-down pro-mobile-switcher__chevron" aria-hidden="true"></i>
                        </summary>
                        <div class="pro-mobile-switcher__menu" role="listbox" aria-label="Ваші профілі">
                            @foreach ($ownedProfiles as $profileOption)
                                @php $isCurrentSwitcher = $currentProfile && $currentProfile->id === $profileOption->id; @endphp
                                {{-- Звичайне посилання на ?profile={id}: selectedProfileId має
                                     #[Url(as: 'profile')], тож перемикання відбувається на mount
                                     без залежності від wire:click (надійно на мобільному). --}}
                                <a
                                    class="pro-mobile-switcher__option {{ $isCurrentSwitcher ? 'is-active' : '' }}"
                                    role="option"
                                    aria-selected="{{ $isCurrentSwitcher ? 'true' : 'false' }}"
                                    href="{{ route('pro.account', ['profile' => $profileOption->id, 'tab' => $tab]) }}"
                                >
                                    <span class="pro-mobile-switcher__avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($profileOption->name ?: 'D', 0, 1)) }}</span>
                                    <span class="pro-mobile-switcher__option-meta">
                                        <strong>{{ $profileOption->name }}</strong>
                                        <small>{{ $profileOption->city ?: ($profileOption->region?->name ?: 'Без локації') }}</small>
                                    </span>
                                    @if ($isCurrentSwitcher)
                                        <i class="fa-solid fa-check pro-mobile-switcher__check" aria-hidden="true"></i>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </details>
                @endif

                @if (session('status') && isset($statusMessages[session('status')]))
                    <p class="account-alert account-alert--success">{{ $statusMessages[session('status')] }}</p>
                @endif
                <div class="pro-profile-toast" data-profile-toast role="status" aria-live="polite" hidden></div>

                @if ($errors->any())
                    <div class="account-alert account-alert--error">
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (! $currentProfile)
                    <section class="card account-block">
                        <div class="account-block__head">
                            <h2>Підключіть профіль до PRO-кабінету</h2>
                            <p>Знайдіть свою компанію через той самий пошук, що працює на платформі, і подайте заявку на керування профілем.</p>
                        </div>
                    </section>
                @endif

                @if ($currentProfile)
                    <div class="pro-account-tab-stack" data-pro-tab-panel="overview" @if ($tab !== 'overview') hidden @endif>
                    @php
                        $overviewRating = max(0.0, min(5.0, (float) ($currentProfile->rating_avg ?? 0)));
                        $overviewFullStars = (int) floor($overviewRating);
                        $overviewHasHalfStar = ($overviewRating - $overviewFullStars) >= 0.5;
                        $overviewEmptyStars = max(0, 5 - $overviewFullStars - ($overviewHasHalfStar ? 1 : 0));
                        $overviewRatingToneClass = match (true) {
                            $overviewRating >= 4 => 'rating-stars--excellent',
                            $overviewRating >= 3 => 'rating-stars--fair',
                            default => 'rating-stars--poor',
                        };
                        $overviewTrustMeta = $overviewTrustMeta ?? [];
                    @endphp
                    <section class="card profile-hero pro-overview-hero">
                        <div class="profile-hero__main">
                            <div class="profile-hero__logo-wrap">
                                <div class="profile-hero__logo review-list-card__logo {{ $logoUrl ? '' : 'has-random-gradient is-fallback' }}" data-profile-image-shell data-seed="{{ $currentProfile->name }}" aria-label="Лого {{ $currentProfile->name }}">
                                    @if ($logoUrl)
                                        <img src="{{ $logoUrl }}" alt="{{ $currentProfile->name }}" data-profile-image>
                                    @endif
                                    <span class="pro-profile-image-fallback" data-profile-image-fallback @if ($logoUrl) hidden @endif>{{ $profileAvatarInitial }}</span>
                                </div>
                                @include('static.partials.verification-badge', [
                                    'verified' => (bool) ($currentProfile->is_verified ?? false),
                                    'ownerVerified' => (bool) ($currentProfile->is_owner_verified || ($currentProfile->has_approved_claim ?? false) || $currentProfile->owner_user_id),
                                    'modifier' => 'owner-verified-badge--hero',
                                ])
                            </div>

                            <div class="profile-hero__info">
                                <h2 class="profile-hero__title">
                                    <span class="profile-hero__title-text">{{ $currentProfile->name }}</span>
                                </h2>

                                @php
                                    $overviewReviewsCount = (int) ($currentProfile->reviews_count ?? 0);
                                    $overviewReviewsLabel = ($overviewReviewsCount % 10 === 1 && $overviewReviewsCount % 100 !== 11)
                                        ? 'відгук'
                                        : (in_array($overviewReviewsCount % 10, [2, 3, 4], true) && !in_array($overviewReviewsCount % 100, [12, 13, 14], true) ? 'відгуки' : 'відгуків');
                                @endphp
                                <div class="profile-hero__meta">
                                    <span>{{ $currentProfile->city ?: ($currentProfile->region?->name ?: 'Україна') }}</span>
                                    <span class="dot">•</span>
                                    <span>{{ $overviewReviewsCount }} {{ $overviewReviewsLabel }}</span>
                                </div>

                                <div class="profile-hero__rating">
                                    <div class="rating-stars {{ $overviewRatingToneClass }}" aria-hidden="true">
                                        @for ($i = 0; $i < $overviewFullStars; $i++)
                                            <i class="fa-solid fa-star"></i>
                                        @endfor
                                        @if ($overviewHasHalfStar)
                                            <i class="fa-solid fa-star-half-stroke"></i>
                                        @endif
                                        @for ($i = 0; $i < $overviewEmptyStars; $i++)
                                            <i class="fa-regular fa-star"></i>
                                        @endfor
                                    </div>
                                    <strong>{{ number_format($overviewRating, 1) }}</strong>
                                    <span>{{ $overviewReviewsCount }} {{ $overviewReviewsLabel }}</span>
                                </div>
                            </div>

                            @php
                                $overviewRank = $overviewTrustMeta['popularity_rank'] ?? null;
                                $overviewRankTotal = (int) ($overviewTrustMeta['popularity_total'] ?? 0);
                            @endphp
                            @if ($overviewReviewsCount > 0)
                                {{-- App-like stats strip: mobile-only replacement for the inline rating row. --}}
                                <div class="profile-hero__stats">
                                    <div class="profile-hero__stat">
                                        <strong>{{ number_format($overviewRating, 1) }} <i class="fa-solid fa-star" aria-hidden="true"></i></strong>
                                        <span>рейтинг</span>
                                    </div>
                                    <div class="profile-hero__stat">
                                        <strong>{{ $overviewReviewsCount }}</strong>
                                        <span>{{ $overviewReviewsLabel }}</span>
                                    </div>
                                    @if ($overviewRank !== null && $overviewRankTotal > 0)
                                        <div class="profile-hero__stat profile-hero__stat--rank" style="--stat-accent: {{ $overviewTrustMeta['popularity_rank_color'] ?? '#30ba73' }}">
                                            <strong>#{{ $overviewRank }}</strong>
                                            <span>із {{ $overviewRankTotal }} у місті</span>
                                        </div>
                                    @endif
                                </div>
                            @endif

                            <div class="profile-hero__actions">
                                @if ($currentProfile->slug)
                                    <a class="btn btn--primary" href="{{ route('profile.show', ['slug' => $currentProfile->slug]) }}">
                                        <i class="fa-regular fa-eye" aria-hidden="true"></i>
                                        <span>Переглянути</span>
                                    </a>
                                @endif
                                <button type="button" class="btn btn--profile-contact" data-pro-tab-trigger data-pro-tab-target="profile">
                                    <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                                    <span>Редагувати</span>
                                </button>
                            </div>
                        </div>

                        <aside
                            class="profile-hero__trust pro-overview-hero__trust profile-hero__trust--{{ $overviewTrustMeta['trust_tone'] ?? 'excellent' }}"
                            style="--trust-color-accent: {{ $overviewTrustMeta['trust_color_accent'] ?? '#34c77a' }}; --trust-color-soft: {{ $overviewTrustMeta['trust_color_soft'] ?? '#eaf9f1' }}; --popularity-dot-color: {{ $overviewTrustMeta['popularity_rank_color'] ?? '#9fb0cf' }};"
                        >
                            <div class="profile-hero__trust-top">
                                <div class="profile-hero__trust-copy">
                                    <p class="profile-hero__trust-label">
                                        Рейтинг довіри
                                        <span
                                            class="profile-hero__trust-help"
                                            tabindex="0"
                                            aria-label="Пояснення рейтингу довіри"
                                        >
                                            <i class="fa-regular fa-circle-question" aria-hidden="true"></i>
                                            <span class="profile-hero__trust-help-tooltip" role="tooltip">
                                                Рейтинг довіри формується за оцінками та відгуками, а також за позицією профілю у категорії.
                                            </span>
                                        </span>
                                    </p>
                                    <p class="profile-hero__trust-rating">
                                        <strong>{{ number_format($overviewRating, 1) }}</strong>
                                        <span>/5</span>
                                    </p>
                                    <p class="profile-hero__trust-reviews">{{ (int) ($currentProfile->reviews_count ?? 0) }} відгуків</p>
                                </div>
                                <div class="profile-hero__trust-icon" aria-hidden="true">
                                    <span class="profile-hero__trust-icon-core">
                                        <span class="profile-hero__trust-badge">
                                            <i class="fa-solid fa-shield-halved"></i>
                                        </span>
                                    </span>
                                </div>
                            </div>

                            <div class="profile-hero__trust-list">
                                <div class="profile-hero__trust-item">
                                    <span class="profile-hero__trust-item-left" title="{{ $overviewTrustMeta['popularity_rank_title'] ?? '' }}">
                                        <i class="fa-solid fa-circle profile-hero__trust-dot" aria-hidden="true"></i>
                                        {{ $overviewTrustMeta['popularity_rank_label'] ?? 'Популярність: —' }}
                                    </span>
                                </div>
                            </div>
                        </aside>
                    </section>

                    @if ($overviewAttentionItems->isEmpty())
                        <section class="card pro-overview-card pro-overview-card--all-clear">
                            <p class="pro-overview-all-clear">
                                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                <span>Все гаразд — критичних завдань зараз немає.</span>
                            </p>
                        </section>
                    @else
                    <section class="card pro-overview-card">
                        <div class="pro-overview-card__head">
                            <h2>Потребує уваги</h2>
                            <button type="button" data-pro-tab-trigger data-pro-tab-target="reviews">Переглянути все</button>
                        </div>

                        <div class="pro-overview-attention-grid" style="--attention-cols: {{ min($overviewAttentionItems->count(), 4) }}">
                            @foreach ($overviewAttentionItems as $item)
                                @php
                                    $attentionItemsCount = (int) ($item['badge_count'] ?? 0);
                                    $attentionSummary = (string) ($item['summary'] ?? '');
                                    $attentionTabTarget = (string) ($item['tab'] ?? 'overview');
                                    $attentionScrollTarget = $item['scroll'] ?? null;
                                @endphp
                                <article class="pro-overview-attention-card tone-{{ $item['tone'] ?? 'info' }}">
                                    @if ($attentionItemsCount > 0)
                                        <span class="pro-overview-attention-card__count tone-{{ $item['tone'] ?? 'info' }}">{{ $attentionItemsCount }}</span>
                                    @endif

                                    <div class="pro-overview-attention-card__head">
                                        <span class="pro-overview-attention-card__icon tone-{{ $item['tone'] ?? 'info' }}">
                                            <i class="fa-solid {{ $item['icon'] ?? 'fa-circle-info' }}" aria-hidden="true"></i>
                                        </span>

                                        <div class="pro-overview-attention-card__content">
                                            <div class="pro-overview-attention-card__title-row">
                                                <strong>{{ $item['title'] }}</strong>
                                            </div>

                                            <p class="pro-overview-attention-card__summary">{{ $attentionSummary }}</p>
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        class="pro-overview-attention-card__action"
                                        data-pro-tab-trigger
                                        data-pro-tab-target="{{ $attentionTabTarget }}"
                                        @if ($attentionScrollTarget) data-pro-tab-scroll="{{ $attentionScrollTarget }}" @endif
                                        aria-label="Перейти до розділу {{ $item['title'] }}"
                                    >
                                        <i class="fa-solid fa-angle-right" aria-hidden="true"></i>
                                    </button>
                                </article>
                            @endforeach
                        </div>
                    </section>
                    @endif

                    <section class="pro-overview-metrics">
                        @foreach ($overviewMetricCards as $metric)
                            <article class="card pro-overview-metric-card tone-{{ $metric['tone'] }}">
                                <span class="pro-overview-metric-card__icon">
                                    <i class="{{ $metric['icon'] }}" aria-hidden="true"></i>
                                </span>
                                <div class="pro-overview-metric-card__content">
                                    <div class="pro-overview-metric-card__value-row">
                                        <strong>{{ $metric['value'] }}</strong>
                                        @if ($metric['label'] === 'Середній рейтинг')
                                            <span>/5</span>
                                        @endif
                                    </div>
                                    <p>{{ $metric['label'] }}</p>
                                    @if (is_array($metric['trend'] ?? null))
                                        <small class="pro-overview-metric-card__meta {{ ($metric['trend']['kind'] ?? null) === 'flat' ? 'is-neutral' : (($metric['trend']['is_up'] ?? true) ? 'is-positive' : 'is-negative') }}">
                                            <b>{{ $metric['trend']['value'] ?? '0' }}</b>
                                            <span>{{ $metric['trend']['detail'] ?? $metric['subtext'] }}</span>
                                        </small>
                                    @else
                                        <small class="pro-overview-metric-card__meta is-static">
                                            <span>{{ $metric['subtext'] }}</span>
                                        </small>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </section>

                    <section class="pro-overview-bottom-grid">
                        @if ($canViewAnalytics ?? false)
                        <section class="card pro-overview-card pro-overview-chart-card" id="overview-analytics" data-pro-overview-analytics data-profile-id="{{ $currentProfile->id }}">
                            <div class="pro-overview-card__head pro-overview-chart-head">
                                <h2>Динаміка переглядів профілю</h2>
                                <div class="pro-chart-controls">
                                    {{-- Сегмент-перемикач періодів: миттєвий AJAX без
                                         перезавантаження (pro.js → /pro/account/analytics). --}}
                                    <div class="pro-chart-periods" role="tablist" aria-label="Період графіка">
                                        @foreach ($analyticsPeriodOptions as $periodOption)
                                            @php
                                                $periodShort = match ($periodOption['key']) {
                                                    'today' => 'Сьогодні',
                                                    'last_7' => '7 дн',
                                                    'last_30' => '30 дн',
                                                    'last_90' => '90 дн',
                                                    default => $periodOption['label'],
                                                };
                                            @endphp
                                            <button
                                                type="button"
                                                class="pro-chart-periods__option {{ $analyticsPeriod === $periodOption['key'] ? 'is-active' : '' }}"
                                                data-chart-period-option
                                                data-period="{{ $periodOption['key'] }}"
                                                role="tab"
                                                aria-selected="{{ $analyticsPeriod === $periodOption['key'] ? 'true' : 'false' }}"
                                                title="{{ $periodOption['label'] }}"
                                            >{{ $periodShort }}</button>
                                        @endforeach
                                    </div>
                                    <button type="button" class="pro-chart-all-btn" data-pro-tab-trigger data-pro-tab-target="analytics">
                                        <span>Вся статистика</span>
                                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="pro-overview-chart">
                                <div class="pro-overview-chart__summary">
                                    <div class="pro-overview-chart__summary-main">
                                        <strong data-chart-summary-value>{{ number_format((int) data_get($analytics ?? [], 'metrics.views.current', 0), 0, '.', ' ') }}</strong>
                                        <span data-chart-summary-subtitle>переглядів {{ $analyticsPeriodContextLabel }}</span>
                                    </div>
                                    <div class="pro-overview-chart__summary-trend {{ ($trendDisplays['views']['kind'] ?? null) === 'flat' ? 'is-neutral' : (($trendDisplays['views']['is_up'] ?? true) ? 'is-positive' : 'is-negative') }}" data-chart-summary-trend>
                                        <i class="fa-solid {{ ($trendDisplays['views']['kind'] ?? null) === 'flat' ? 'fa-minus' : (($trendDisplays['views']['is_up'] ?? true) ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down') }}" aria-hidden="true" data-chart-summary-trend-icon></i>
                                        <span data-chart-summary-trend-value>{{ $trendDisplays['views']['value'] ?? '0' }}</span>
                                    </div>
                                </div>

                                <div class="pro-overview-chart__canvas">
                                    <canvas
                                        class="pro-overview-chart__surface"
                                        data-pro-overview-chart
                                        data-chart='@json($chartPayload)'
                                        aria-label="Графік динаміки переглядів профілю"
                                    ></canvas>

                                    @unless ($chartHasMeaningfulData)
                                        <div class="pro-overview-chart__empty">
                                            <strong>Ще немає достатньо даних</strong>
                                            <span>Коли профіль почне отримувати перегляди, тут з’явиться динаміка {{ $analyticsPeriodContextLabel }}.</span>
                                        </div>
                                    @endunless
                                </div>
                            </div>
                        </section>
                        @endif

                        @include('pro.partials.completion-card', [
                            'title' => 'Заповнення профілю',
                            'progress' => $completionPercentValue,
                            'progressLabel' => 'заповнено',
                            'items' => $completionItems,
                            'action' => [
                                'href' => route('pro.account', ['tab' => 'profile', 'profile' => $currentProfile->id]),
                                'icon' => 'fa-pen-to-square',
                                'label' => 'Редагувати профіль',
                                'tab_target' => 'profile',
                            ],
                        ])

                        <section class="card pro-overview-card pro-overview-reviews-card">
                            <div class="pro-overview-card__head">
                                <h2>Останні відгуки</h2>
                                <button type="button" data-pro-tab-trigger data-pro-tab-target="reviews">Переглянути всі</button>
                            </div>

                            <div class="pro-overview-reviews-card__list">
                                @forelse ($latestReviewsForOverview as $review)
                                    @php
                                        $reviewFull = (int) floor($review['rating']);
                                        $reviewHalf = (($review['rating'] - $reviewFull) >= 0.5);
                                        $reviewEmpty = 5 - $reviewFull - ($reviewHalf ? 1 : 0);
                                    @endphp
                                    <div class="pro-overview-reviews-card__entry">
                                        <article class="pro-overview-review-compact" data-review-rating="{{ (int) floor((float) $review['rating']) }}" data-review-index="{{ $loop->index }}" data-review-date="{{ $review['date_iso'] ?? ($review['date'] ?? '') }}">
                                            <div class="pro-overview-review-compact__top">
                                                <div class="pro-overview-review-compact__author">
                                                    <div class="pro-overview-review-compact__avatar review-list-card__logo {{ empty($review['avatar_url']) ? 'has-random-gradient' : '' }}" data-review-avatar data-seed="{{ $review['author'] }}">
                                                        @if (!empty($review['avatar_url']))
                                                            <img src="{{ $review['avatar_url'] }}" alt="{{ $review['author'] }}" loading="lazy" data-review-avatar-image>
                                                        @endif
                                                        <span class="pro-review-avatar-fallback" data-review-avatar-fallback @if (!empty($review['avatar_url'])) hidden @endif>{{ $review['avatar'] ?? 'К' }}</span>
                                                    </div>
                                                    <div class="pro-overview-review-compact__meta">
                                                        <strong>{{ \Illuminate\Support\Str::limit($review['author'], 26) }}</strong>
                                                        <div class="pro-overview-review-compact__rating">
                                                            <div class="rating-stars">
                                                                @for ($i = 0; $i < $reviewFull; $i++)
                                                                    <i class="fa-solid fa-star" aria-hidden="true"></i>
                                                                @endfor
                                                                @if ($reviewHalf)
                                                                    <i class="fa-solid fa-star-half-stroke" aria-hidden="true"></i>
                                                                @endif
                                                                @for ($i = 0; $i < $reviewEmpty; $i++)
                                                                    <i class="fa-regular fa-star" aria-hidden="true"></i>
                                                                @endfor
                                                            </div>
                                                            <span>{{ number_format((float) $review['rating'], 1) }}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <time datetime="{{ $review['date_iso'] ?? '' }}">{{ $review['date'] }}</time>
                                            </div>
                                            <p class="pro-overview-review-compact__text">{{ \Illuminate\Support\Str::limit((string) ($review['title'] ?: $review['text']), 96) }}</p>
                                        </article>
                                    </div>
                                @empty
                                    <p class="account-empty">Ще немає відгуків для відображення.</p>
                                @endforelse
                            </div>
                        </section>
                    </section>

                    @include('pro.partials.review-collection-card')
                    @include('pro.partials.widget-card')
                    </div>
                @endif

                @if ($currentProfile && ($canViewAnalytics ?? false))
                    <div class="pro-account-tab-stack" data-pro-tab-panel="analytics" @if ($tab !== 'analytics') hidden @endif>
                    <section class="pro-analytics-workspace" id="analytics-workspace">
                        {{-- Профільну шапку прибрано (дублювала огляд); лишаємо тільки
                             перемикач періоду. --}}
                        <section class="card pro-analytics-hero pro-analytics-hero--switcher-only">
                            <div class="pro-analytics-period-switcher" aria-label="Період аналітики">
                                @foreach ($analyticsPeriodOptions as $periodOption)
                                    <a
                                        href="{{ route('pro.account', ['tab' => 'analytics', 'profile' => $currentProfile->id, 'analytics_period' => $periodOption['key']]) }}"
                                        class="pro-analytics-period-switcher__item {{ $analyticsPeriod === $periodOption['key'] ? 'is-active' : '' }}"
                                    >
                                        {{ str_replace(['Останні ', 'Сьогодні'], ['', 'Сьогодні'], $periodOption['label']) }}
                                    </a>
                                @endforeach
                            </div>
                        </section>

                        <section class="pro-analytics-metrics">
                            @foreach ($analyticsMetricCards as $metric)
                                <article class="card pro-analytics-metric tone-{{ $metric['tone'] }}">
                                    <span class="pro-analytics-metric__icon">
                                        <i class="{{ $metric['icon'] }}" aria-hidden="true"></i>
                                    </span>
                                    <div class="pro-analytics-metric__content">
                                        <p>{{ $metric['label'] }}</p>
                                        <div class="pro-analytics-metric__value">
                                            <strong>{{ $metric['value'] }}</strong>
                                            @if (!empty($metric['suffix']))
                                                <span>{{ $metric['suffix'] }}</span>
                                            @endif
                                        </div>
                                        @if (!empty($metric['hint']))
                                            <small class="is-neutral">{{ $metric['hint'] }}</small>
                                        @elseif (is_array($metric['trend'] ?? null))
                                            <small class="{{ ($metric['trend']['kind'] ?? null) === 'flat' ? 'is-neutral' : (($metric['trend']['is_up'] ?? true) ? 'is-positive' : 'is-negative') }}">
                                                {{ $metric['trend']['value'] ?? '0' }}
                                                <span>{{ $metric['trend']['detail'] ?? 'vs попер. період' }}</span>
                                            </small>
                                        @else
                                            <small class="is-neutral">{{ $analyticsPeriodContextLabel }}</small>
                                        @endif
                                    </div>
                                </article>
                            @endforeach
                        </section>

                        <section class="pro-analytics-chart-grid">
                            <article class="card pro-analytics-card pro-analytics-chart-card" data-pro-analytics-chart-card>
                                <div class="pro-analytics-card__head">
                                    <div>
                                        <h2>Динаміка переглядів профілю та кліків</h2>
                                        <p>Порівняння трафіку і контактних дій {{ $analyticsPeriodContextLabel }}.</p>
                                    </div>
                                    <span class="pro-analytics-card__badge">{{ $analyticsPeriodShortLabel }}</span>
                                </div>
                                <div class="pro-analytics-chart-legend">
                                    <span><i style="--legend-color:#2f6df6"></i>Перегляди профілю</span>
                                    <span><i style="--legend-color:#2fbf74"></i>Кліки на контакти</span>
                                </div>
                                <div class="pro-analytics-chart">
                                    <canvas data-pro-analytics-chart data-chart='@json($analyticsTrafficChartPayload)' aria-label="Графік переглядів і кліків"></canvas>
                                    @if (! collect($viewTrend)->contains(fn ($value) => (int) $value > 0) && ! collect($contactTrend)->contains(fn ($value) => (int) $value > 0))
                                        <div class="pro-analytics-chart__empty">
                                            <strong>Даних поки недостатньо</strong>
                                            <span>Після перших переглядів і кліків тут з’явиться динаміка.</span>
                                        </div>
                                    @endif
                                </div>
                            </article>

                            <article class="card pro-analytics-card pro-analytics-chart-card" data-pro-analytics-chart-card>
                                <div class="pro-analytics-card__head">
                                    <div>
                                        <h2>Динаміка відгуків та рейтингу</h2>
                                        <p>Нові відгуки і середній рейтинг за обраний період.</p>
                                    </div>
                                    <span class="pro-analytics-card__badge">{{ number_format((float) ($currentProfile->rating_avg ?? 0), 1) }}/5</span>
                                </div>
                                <div class="pro-analytics-chart-legend">
                                    <span><i style="--legend-color:#9b7cf6"></i>Нові відгуки</span>
                                    <span><i style="--legend-color:#f5a300"></i>Середній рейтинг</span>
                                </div>
                                <div class="pro-analytics-chart">
                                    <canvas data-pro-analytics-chart data-chart='@json($analyticsReviewsChartPayload)' aria-label="Графік відгуків і рейтингу"></canvas>
                                    @if (! collect($reviewsRatingTimeline['review_counts'] ?? [])->contains(fn ($value) => (int) $value > 0))
                                        <div class="pro-analytics-chart__empty">
                                            <strong>Нових відгуків немає</strong>
                                            <span>Коли з’являться відгуки за період, графік оновиться.</span>
                                        </div>
                                    @endif
                                </div>
                            </article>
                        </section>

                        <section class="pro-analytics-lower-grid">
                            <article class="card pro-analytics-card pro-analytics-sources-card">
                                <div class="pro-analytics-card__head">
                                    <div>
                                        <h2>Джерела трафіку</h2>
                                        <p>{{ $analyticsSourcesTotal > 0 ? 'Звідки приходять перегляди профілю.' : 'Джерела з’являться після перших переглядів.' }}</p>
                                    </div>
                                </div>

                                <div class="pro-analytics-sources">
                                    <div class="pro-analytics-sources__ring {{ $analyticsSourcesTotal <= 0 ? 'is-empty' : '' }}">
                                        <strong>{{ number_format($analyticsSourcesTotal, 0, '.', ' ') }}</strong>
                                        <span>переглядів</span>
                                    </div>
                                    <div class="pro-analytics-sources__list">
                                        @forelse ($analyticsSources as $source)
                                            <div class="pro-analytics-source-row" style="--source-share: {{ (int) ($source['share'] ?? 0) }}%">
                                                <span>{{ $source['label'] }}</span>
                                                <strong>{{ (int) ($source['share'] ?? 0) }}% <small>({{ (int) ($source['value'] ?? 0) }})</small></strong>
                                            </div>
                                        @empty
                                            <p class="account-empty">Поки немає джерел трафіку для цього періоду.</p>
                                        @endforelse
                                    </div>
                                </div>

                                @php
                                    $impressionContexts = collect((array) data_get($analytics ?? [], 'impressions_by_context', []));
                                    $impressionContextsTotal = (int) $impressionContexts->sum('value');
                                @endphp
                                @if ($impressionContextsTotal > 0)
                                    {{-- Де саме бачили картку профілю (контексти показів) —
                                         аргумент цінності PRO і бази під майбутню рекламу. --}}
                                    <div class="pro-analytics-card__head" style="margin-top: 18px;">
                                        <div>
                                            <h2 style="font-size: 1rem;">Де вас бачили</h2>
                                            <p>Покази картки профілю за період: {{ number_format($impressionContextsTotal, 0, '.', ' ') }}.</p>
                                        </div>
                                    </div>
                                    <div class="pro-analytics-sources__list" style="margin-top: 6px;">
                                        @foreach ($impressionContexts as $context)
                                            <div class="pro-analytics-source-row" style="--source-share: {{ (int) round(($context['value'] ?? 0) / max(1, $impressionContextsTotal) * 100) }}%">
                                                <span>{{ $context['label'] }}</span>
                                                <strong>{{ (int) round(($context['value'] ?? 0) / max(1, $impressionContextsTotal) * 100) }}% <small>({{ (int) ($context['value'] ?? 0) }})</small></strong>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </article>

                            <article class="card pro-analytics-card pro-analytics-actions-card">
                                <div class="pro-analytics-card__head">
                                    <div>
                                        <h2>Найефективніші дії користувачів</h2>
                                        <p>Кліки по контактах, сайту і маршруту за обраний період.</p>
                                    </div>
                                </div>

                                <div class="pro-analytics-actions-table">
                                    <div class="pro-analytics-actions-table__head">
                                        <span>Дія</span>
                                        <span>Кількість</span>
                                        <span>Зміна</span>
                                    </div>
                                    @foreach ($analyticsActionRows as $row)
                                        <div class="pro-analytics-actions-table__row">
                                            <span>
                                                <i class="fa-solid {{ $row['icon'] }}" aria-hidden="true"></i>
                                                {{ $row['label'] }}
                                            </span>
                                            <strong>{{ number_format((int) ($row['value'] ?? 0), 0, '.', ' ') }}</strong>
                                            <em class="{{ ($row['trend']['kind'] ?? null) === 'flat' ? 'is-neutral' : (($row['trend']['is_up'] ?? true) ? 'is-positive' : 'is-negative') }}">
                                                {{ $row['trend']['value'] ?? '0' }}
                                            </em>
                                        </div>
                                    @endforeach
                                </div>
                            </article>
                        </section>

                        <p class="pro-analytics-updated">
                            <i class="fa-regular fa-clock" aria-hidden="true"></i>
                            Показники оновлюються щодня. Поточний зріз: {{ now()->format('H:i') }}
                        </p>
                    </section>
                    </div>
                @endif

                @if ($currentProfile && ($leadsData['available'] ?? false))
                    <div class="pro-account-tab-stack" data-pro-tab-panel="leads" @if ($tab !== 'leads') hidden @endif>
                        <section class="pro-leads-workspace">
                            <div class="pro-leads-head">
                                <h2>Заявки від клієнтів</h2>
                                <p>Це звернення, які Dovira привела на ваш профіль. Передзвонюйте якнайшвидше — теплі ліди холонуть за години.</p>
                            </div>

                            @php $leadStatusCounts = $leadsData['status_counts'] ?? ['new' => 0, 'in_progress' => 0, 'done' => 0]; @endphp
                            @if (!empty($leadsData['items']))
                                <div class="pro-leads-filters" data-lead-filters role="group" aria-label="Фільтр заявок за статусом">
                                    <button type="button" class="pro-leads-filters__btn is-active" data-lead-filter="all">Всі <b>{{ (int) ($leadsData['total'] ?? 0) }}</b></button>
                                    <button type="button" class="pro-leads-filters__btn" data-lead-filter="new">Нові <b data-lead-count="new">{{ $leadStatusCounts['new'] }}</b></button>
                                    <button type="button" class="pro-leads-filters__btn" data-lead-filter="in_progress">В роботі <b data-lead-count="in_progress">{{ $leadStatusCounts['in_progress'] }}</b></button>
                                    <button type="button" class="pro-leads-filters__btn" data-lead-filter="done">Завершені <b data-lead-count="done">{{ $leadStatusCounts['done'] }}</b></button>
                                </div>
                            @endif

                            @if (empty($leadsData['items']))
                                <div class="card pro-leads-empty">
                                    <span class="pro-leads-empty__icon"><i class="fa-regular fa-comments" aria-hidden="true"></i></span>
                                    <strong>Заявок поки немає</strong>
                                    <p>Форма «Залишити заявку» вже працює на вашому публічному профілі. Заохочуйте клієнтів звертатися — і ліди зʼявляться тут.</p>
                                </div>
                            @else
                                @unless ($leadsData['is_pro'])
                                    <div class="card pro-leads-gate">
                                        <span class="pro-leads-gate__icon"><i class="fa-solid fa-lock" aria-hidden="true"></i></span>
                                        <div>
                                            <strong>У вас {{ $leadsData['total'] }} {{ \Illuminate\Support\Str::plural('заявка', $leadsData['total']) }} — контакти приховані</strong>
                                            <p>Оформіть підписку, щоб бачити імена й телефони клієнтів і передзвонювати їм.</p>
                                        </div>
                                        <a href="{{ route('pro.account', ['tab' => 'billing', 'profile' => $currentProfile->id]) }}" class="btn btn--primary account-btn--mini">Оформити підписку</a>
                                    </div>
                                @endunless

                                <div class="pro-leads-list" data-lead-list>
                                    @foreach ($leadsData['items'] as $lead)
                                        <article
                                            class="card pro-lead-item {{ $lead['is_read'] ? '' : 'is-unread' }} {{ $leadsData['is_pro'] ? '' : 'is-locked' }}"
                                            data-lead-item
                                            data-lead-id="{{ $lead['id'] }}"
                                            data-lead-status="{{ $lead['status'] }}"
                                        >
                                            <div class="pro-lead-item__main">
                                                <div class="pro-lead-item__top">
                                                    <strong class="pro-lead-item__name">{{ $lead['name'] }}</strong>
                                                    <span class="pro-lead-item__date">{{ $lead['date'] }}</span>
                                                </div>
                                                <a @if($leadsData['is_pro']) href="tel:{{ preg_replace('/[^0-9+]/', '', $lead['phone']) }}" @endif class="pro-lead-item__phone">
                                                    <i class="fa-solid fa-phone" aria-hidden="true"></i> {{ $lead['phone'] }}
                                                </a>
                                                @if (!empty($lead['message']))
                                                    <p class="pro-lead-item__message">{{ $lead['message'] }}</p>
                                                @endif
                                                @if ($leadsData['is_pro'])
                                                    <div class="pro-lead-item__statuses" data-lead-status-url="{{ url('/pro/account/leads') }}/{{ $lead['id'] }}/status" role="group" aria-label="Статус заявки">
                                                        <button type="button" class="pro-lead-status-btn pro-lead-status-btn--new {{ $lead['status'] === 'new' ? 'is-active' : '' }}" data-lead-set-status="new">Нова</button>
                                                        <button type="button" class="pro-lead-status-btn pro-lead-status-btn--progress {{ $lead['status'] === 'in_progress' ? 'is-active' : '' }}" data-lead-set-status="in_progress">В роботі</button>
                                                        <button type="button" class="pro-lead-status-btn pro-lead-status-btn--done {{ $lead['status'] === 'done' ? 'is-active' : '' }}" data-lead-set-status="done">Завершена</button>
                                                    </div>
                                                @endif
                                            </div>
                                        </article>
                                    @endforeach
                                </div>

                                <style>
                                    .pro-leads-filters { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 14px; }
                                    .pro-leads-filters__btn { display: inline-flex; align-items: center; gap: 7px; padding: 8px 14px; border: 1px solid var(--c-border, rgba(15,23,42,.12)); border-radius: 999px; background: var(--c-surface, #fff); color: var(--c-muted, #64748b); font: inherit; font-size: 13.5px; font-weight: 650; cursor: pointer; }
                                    .pro-leads-filters__btn b { font-weight: 800; }
                                    .pro-leads-filters__btn.is-active { border-color: var(--c-primary, #2563eb); background: #e8f0ff; color: var(--c-primary-dark, #1d4ed8); }
                                    .pro-lead-item__statuses { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
                                    .pro-lead-status-btn { padding: 5px 12px; border: 1px solid var(--c-border, rgba(15,23,42,.14)); border-radius: 999px; background: transparent; color: var(--c-muted, #64748b); font: inherit; font-size: 12.5px; font-weight: 650; cursor: pointer; transition: background .12s ease, color .12s ease, border-color .12s ease; }
                                    .pro-lead-status-btn[disabled] { opacity: .55; cursor: wait; }
                                    .pro-lead-status-btn--new.is-active { background: #e8f0ff; border-color: rgba(37,99,235,.45); color: #1d4ed8; }
                                    .pro-lead-status-btn--progress.is-active { background: #fdf3e0; border-color: rgba(217,145,26,.45); color: #b26a00; }
                                    .pro-lead-status-btn--done.is-active { background: #e2f7ec; border-color: rgba(22,163,74,.4); color: #15803d; }
                                    .pro-lead-item[data-lead-status="done"] { opacity: .72; }
                                </style>
                            @endif
                        </section>
                    </div>
                @endif

                @if ($currentProfile)
                    <div class="pro-account-tab-stack" data-pro-tab-panel="notifications" @if ($tab !== 'notifications') hidden @endif>
                    <section class="pro-notifications-workspace">
                        {{-- Компактний settings-список: групи з тонкими рядками
                             (назва + підказка + маленький тумблер), без картки-на-тумблер. --}}
                        <section class="card pro-notif">
                            <div class="pro-notif__head">
                                <h2>Сповіщення</h2>
                                <span class="pro-notif__chip"><span data-pro-notifications-unread-count>{{ (int) ($notificationUnreadCount ?? 0) }}</span> непрочитаних</span>
                            </div>

                            <form
                                class="pro-notif__form"
                                method="POST"
                                action="{{ route('pro.account.notifications.preferences', $currentProfile) }}"
                                data-pro-notifications-form
                            >
                                @csrf
                                @method('PATCH')

                                @foreach (($notificationGroups ?? []) as $group)
                                    <div class="pro-notif__group">
                                        <p class="pro-notif__group-label">{{ $group['title'] }}</p>
                                        @foreach (($group['items'] ?? []) as $item)
                                            <label class="pro-notif__row">
                                                <span class="pro-notif__row-text">
                                                    <span class="pro-notif__row-title">{{ $item['label'] }}</span>
                                                    @if (!empty($item['description']))
                                                        <span class="pro-notif__row-hint">{{ $item['description'] }}</span>
                                                    @endif
                                                </span>
                                                <span class="pro-notif__toggle">
                                                    <input
                                                        type="checkbox"
                                                        name="{{ $item['key'] }}"
                                                        value="1"
                                                        @checked((bool) ($notificationPreferences[$item['key']] ?? false))
                                                    >
                                                    <span class="pro-notif__toggle-track"></span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endforeach
                            </form>

                            @php
                                $tgConnectUrl = \App\Services\Notifications\TelegramOwnerNotifier::connectUrl($user);
                                $tgConnected = filled($user->telegram_notify_chat_id ?? null);
                            @endphp
                            @if ($tgConnectUrl)
                                <div class="pro-notif__telegram" style="display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; margin-top:16px; padding:14px 16px; border:1px solid {{ $tgConnected ? 'rgba(22,163,74,.35)' : 'rgba(34,158,217,.35)' }}; border-radius:14px; background: {{ $tgConnected ? '#f0fdf4' : '#f2fafd' }};">
                                    <div style="display:flex; gap:11px; align-items:flex-start;">
                                        <i class="fa-brands fa-telegram" aria-hidden="true" style="margin-top:3px; font-size:20px; color:#229ed9;"></i>
                                        <div>
                                            <strong style="display:block; font-size:14.5px;">{{ $tgConnected ? 'Telegram підключено' : 'Підключіть Telegram-бота' }}</strong>
                                            <p style="margin:3px 0 0; font-size:13px; color:var(--c-muted, #64748b);">
                                                {{ $tgConnected
                                                    ? 'Нові заявки й відгуки приходять вам у Telegram. Вимкнути: /stop у боті або тумблер «Telegram» вище.'
                                                    : 'Натисніть кнопку і надішліть боту /start — нові заявки й відгуки прилітатимуть миттєво.' }}
                                            </p>
                                        </div>
                                    </div>
                                    @unless ($tgConnected)
                                        <a class="btn btn--primary account-btn--mini" href="{{ $tgConnectUrl }}" target="_blank" rel="noopener noreferrer" style="background:#229ed9;">
                                            <i class="fa-brands fa-telegram" aria-hidden="true"></i>
                                            <span>Підключити</span>
                                        </a>
                                    @endunless
                                </div>
                            @endif
                        </section>

                        <section class="card pro-notifications-card">
                            <div class="pro-notifications-card__head">
                                <div>
                                    <h2>Останні сповіщення</h2>
                                    <p>Важливі події по відгуках, оплаті, верифікації та статусу профілю.</p>
                                </div>
                                <button
                                    type="button"
                                    class="btn btn--ghost account-btn--mini"
                                    data-pro-notifications-read-all
                                    data-url="{{ route('pro.account.notifications.read-all', $currentProfile) }}"
                                    @disabled(($notificationUnreadCount ?? 0) <= 0)
                                >
                                    <i class="fa-solid fa-check-double" aria-hidden="true"></i>
                                    <span>Позначити все прочитаним</span>
                                </button>
                            </div>

                            <div class="pro-notifications-list" data-pro-notifications-list>
                                @forelse (($profileNotifications ?? collect()) as $notification)
                                    <article class="pro-notification-item tone-{{ $notification['severity'] ?? 'info' }} {{ !($notification['is_read'] ?? false) ? 'is-unread' : '' }}">
                                        <div class="pro-notification-item__dot" aria-hidden="true"></div>
                                        <div class="pro-notification-item__body">
                                            <div class="pro-notification-item__top">
                                                <h3>{{ $notification['title'] }}</h3>
                                                <time>{{ $notification['created_at_label'] }}</time>
                                            </div>
                                            @if (!empty($notification['body']))
                                                <p>{{ $notification['body'] }}</p>
                                            @endif
                                            @if (!empty($notification['action_url']))
                                                <a href="{{ $notification['action_url'] }}" class="pro-notification-item__action">
                                                    {{ $notification['action_label'] ?? 'Переглянути' }}
                                                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                                </a>
                                            @endif
                                        </div>
                                    </article>
                                @empty
                                    <p class="account-empty">Поки що немає сповіщень по цьому профілю.</p>
                                @endforelse
                            </div>
                        </section>
                    </section>
                    </div>
                @endif

                @if ($currentProfile)
                    <div class="pro-account-tab-stack" data-pro-tab-panel="billing" @if ($tab !== 'billing') hidden @endif>
                    @php
                        $billingIsActive = (bool) ($billingSummary['is_active'] ?? false);
                        $billingPlan = (string) ($billingSummary['plan'] ?? 'basic');
                        $billingPeriod = (string) ($billingSummary['billing_period'] ?? \App\Support\ProPricing::PERIOD_KEY);
                    @endphp
                    <section class="pro-billing-workspace" id="billing-workspace" data-pro-billing-workspace>
                        <div class="pro-billing-hero pro-billing-hero--compact" data-pro-billing-block="hero">
                            <div class="pro-billing-hero__titles">
                                <h2>Оплата та білінг</h2>
                                @if (! $billingIsActive && $sellNumbersLine)
                                    <p><b>{{ $sellNumbersLine }}</b> Заберіть цих клієнтів собі — з PRO ви відповідаєте їм офіційно.</p>
                                @else
                                    <p>Підключайте й продовжуйте PRO через підтверджену оплату monopay.</p>
                                @endif
                                <p style="margin-top: 10px; color: #16a34a; font-size: 0.95rem; font-weight: 600;">
                                    <i class="fa-solid fa-bolt" aria-hidden="true" style="margin-right: 6px;"></i>
                                    {{ \App\Support\ProPricing::launchCohortTitle() }}: {{ \App\Support\ProPricing::formatDisplay(\App\Support\ProPricing::DISPLAY_CURRENT) }} за 6 місяців. Додатково −20% за розміщення бейджа DOVIRA.
                                </p>
                            </div>
                            <div class="pro-billing-hero__eyebrow">
                                <span class="pro-billing-pill is-info">Оплата онлайн</span>
                                <span class="pro-billing-pill is-{{ $billingSummary['status_tone'] ?? 'muted' }}">{{ $billingSummary['status_label'] ?? 'Неактивний' }}</span>
                            </div>
                        </div>

                        {{-- Компактне зведення: одна картка з трьома міні-стовпцями
                             замість трьох великих карток. --}}
                        <section class="card pro-billing-brief" data-pro-billing-block="summary">
                            <div class="pro-billing-brief__item">
                                <span class="pro-billing-brief__label">Поточний план</span>
                                <strong>{{ $billingSummary['plan_label'] ?? 'Start' }}</strong>
                                <span class="pro-billing-brief__sub">{{ $billingSummary['current_price_label'] ?? '0 грн' }} · {{ $billingSummary['billing_period_label'] ?? 'Щомісяця' }}</span>
                            </div>
                            <div class="pro-billing-brief__item">
                                <span class="pro-billing-brief__label">Статус</span>
                                <strong class="pro-billing-brief__status is-{{ $billingSummary['status_tone'] ?? 'muted' }}">{{ $billingSummary['status_label'] ?? 'Неактивний' }}</strong>
                                <span class="pro-billing-brief__sub">Старт: {{ $billingSummary['started_label'] ?? 'Немає даних' }}</span>
                            </div>
                            <div class="pro-billing-brief__item">
                                <span class="pro-billing-brief__label">Наступний платіж</span>
                                <strong>{{ $billingSummary['next_payment_label'] ?? 'Не заплановано' }}</strong>
                                <span class="pro-billing-brief__sub">{{ $billingSummary['next_charge_amount_label'] ?? '0 грн' }}</span>
                            </div>
                        </section>

                        <section class="pro-billing-main-grid">
                            <article class="card pro-billing-card pro-plans" id="billing-plans" data-pro-billing-block="plans">
                                <div class="pro-plans__head">
                                    <h2>Тарифи PRO</h2>
                                    <span class="pro-plans__promo-note">
                                        <i class="fa-solid fa-bolt" aria-hidden="true"></i>
                                        {{ \App\Support\ProPricing::formatDisplay(\App\Support\ProPricing::DISPLAY_CURRENT) }} за стартовою пропозицією — далі {{ \App\Support\ProPricing::formatDisplay(\App\Support\ProPricing::DISPLAY_FUTURE) }}
                                    </span>
                                </div>

                                <div class="pro-billing-plan-list" data-pro-billing-plan-list data-current-plan="{{ $billingIsActive ? $billingPlan : 'start' }}" data-current-period="{{ $billingPeriod }}">
                                    <div class="pro-billing-plan pro-billing-plan--free {{ ! $billingIsActive ? 'is-active' : '' }}" data-pro-billing-plan-card data-plan="start">
                                        <div class="pro-billing-plan__name">
                                            <strong>Start</strong>
                                            <small data-pro-billing-current-badge @if ($billingIsActive) hidden @endif>Поточний</small>
                                        </div>
                                        <div class="pro-billing-plan__price">
                                            <strong data-pro-billing-price data-monthly="{{ \App\Support\ProPricing::formatDisplay(0) }}" data-yearly="{{ \App\Support\ProPricing::formatDisplay(0) }}">{{ \App\Support\ProPricing::formatDisplay(0) }}</strong>
                                            <span data-pro-billing-price-period data-monthly="без оплати" data-yearly="без оплати">без оплати</span>
                                        </div>
                                        <ul class="pro-billing-plan__features">
                                            <li>Створення або прив’язка профілю</li>
                                            <li>Редагування опису, контактів і послуг</li>
                                            <li>Публічна сторінка в каталозі DOVIRA</li>
                                        </ul>
                                        <div class="pro-billing-plan__actions">
                                            <button type="button" class="btn btn--ghost account-btn--mini" disabled data-static-disabled>
                                                <span>{{ ! $billingIsActive ? 'Поточний тариф' : 'Входить у PRO' }}</span>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="pro-billing-plan {{ $billingIsActive && $billingPlan === 'pro' ? 'is-active' : '' }}" data-pro-billing-plan-card data-plan="pro">
                                        <div class="pro-billing-plan__name">
                                            <strong>PRO</strong>
                                            <small data-pro-billing-current-badge @unless ($billingIsActive && $billingPlan === 'pro') hidden @endunless>Поточний</small>
                                        </div>
                                        <div class="pro-billing-plan__price">
                                            @if (! $currentProDiscount['has_discount'] && $configuredProAmount === \App\Support\ProPricing::CURRENT_AMOUNT)
                                                <s class="pro-billing-plan__old-price">{{ \App\Support\ProPricing::monthlyEquivalentDisplay(\App\Support\ProPricing::DISPLAY_FUTURE) }}</s>
                                            @elseif ($currentProDiscount['has_discount'])
                                                <s class="pro-billing-plan__old-price">{{ \App\Support\ProPricing::monthlyEquivalentDisplay(\App\Support\ProPricing::DISPLAY_CURRENT) }}</s>
                                            @endif
                                            <strong>≈ {{ \App\Support\ProPricing::formatDisplay(\App\Support\ProPricing::monthlyEquivalent((int) $currentProDisplayDiscount['amount'])) }}</strong>
                                            <span>/ місяць</span>
                                            @if ($currentProDiscount['has_discount'])
                                                <small>−{{ (int) $currentProDiscount['discount_percent'] }}%</small>
                                            @endif
                                        </div>
                                        <p class="pro-billing-plan__note">
                                            {{ $currentProPriceDisplay }} раз на 6 місяців · підписка з автопродовженням, вимкнути можна будь-коли. Стартова ціна діє для першої когорти.
                                        </p>
                                        <ul class="pro-billing-plan__features">
                                            <li>Верхні позиції у видачі категорії</li>
                                            <li>Публічні відповіді на відгуки</li>
                                            <li>Аналітика переглядів і контактних кліків</li>
                                            <li>Оскарження несправедливих відгуків</li>
                                            <li>−20% за бейдж DOVIRA на сайті або в соцмережах</li>
                                        </ul>
                                        <div class="pro-billing-plan__actions">
                                            <form method="POST" action="{{ route('pro.account.billing.checkout', $currentProfile) }}" data-pro-billing-form data-pro-billing-action="checkout" data-pro-billing-plan-form data-plan="pro">
                                                @csrf
                                                <input type="hidden" name="plan" value="pro">
                                                <input type="hidden" name="period" value="{{ \App\Support\ProPricing::PERIOD_KEY }}">
                                                <button type="submit" class="btn {{ $billingIsActive && $billingPlan === 'pro' ? 'btn--ghost' : 'btn--primary' }} account-btn--mini" data-pro-billing-plan-button data-connect-label="Оформити підписку" data-switch-label="Продовжити підписку" data-active-label="Активний зараз">
                                                    <span>{{ $billingIsActive && $billingPlan === 'pro' ? 'Продовжити на 6 місяців' : 'Оформити підписку' }}</span>
                                                </button>
                                            </form>
                                        </div>
                                    </div>

                                </div>

                                @if ($billingSummary['can_cancel'] ?? false)
                                    @php
                                        $autoRenewOn = (bool) ($latestSubscription?->auto_renew ?? false);
                                        $autoRenewPossible = filled($latestSubscription?->monopay_card_token);
                                    @endphp
                                    <div class="pro-plans__foot" style="flex-wrap: wrap; gap: 10px;">
                                        <span>
                                            Підписка діє до {{ $billingSummary['ended_label'] ?? '—' }}
                                            @if ($autoRenewOn)
                                                · автопродовження <b style="color:#16a34a;">увімкнено</b> — наступне списання {{ $latestSubscription?->ends_at?->translatedFormat('d F Y') ?? '—' }}, {{ \App\Support\ProPricing::formatAmount((int) ($latestSubscription?->price_monthly ?: \App\Support\ProPricing::CURRENT_AMOUNT)) }}
                                            @else
                                                · автопродовження вимкнено
                                            @endif
                                        </span>
                                        <span style="display:inline-flex; gap:14px; align-items:center;">
                                            @if ($autoRenewOn || $autoRenewPossible)
                                                <form method="POST" action="{{ route('pro.account.billing.auto-renew', $currentProfile) }}">
                                                    @csrf
                                                    <input type="hidden" name="enabled" value="{{ $autoRenewOn ? 0 : 1 }}">
                                                    <button type="submit" class="pro-plans__cancel">
                                                        <span>{{ $autoRenewOn ? 'Вимкнути автопродовження' : 'Увімкнути автопродовження' }}</span>
                                                    </button>
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ route('pro.account.billing.cancel', $currentProfile) }}" data-pro-billing-form data-pro-billing-action="cancel">
                                                @csrf
                                                <button type="submit" class="pro-plans__cancel">
                                                    <span>Скасувати підписку</span>
                                                </button>
                                            </form>
                                        </span>
                                    </div>
                                @endif
                            </article>
                        </section>

                        <section class="card pro-billing-card" data-pro-billing-block="history">
                            <div class="pro-billing-card__head">
                                <h2>Останні платежі</h2>
                                <p>Історія формується з підтверджених оплат цього профілю.</p>
                            </div>

                            <div class="pro-billing-table">
                                <div class="pro-billing-table__head">
                                    <span>Дата</span>
                                    <span>Опис</span>
                                    <span>Сума</span>
                                    <span>Статус</span>
                                    <span>Документ</span>
                                </div>

                                @forelse (($billingSummary['payments'] ?? []) as $payment)
                                    <div class="pro-billing-table__row">
                                        <span>{{ $payment['date'] }}</span>
                                        <span class="pro-billing-table__description">
                                            <strong>{{ $payment['description'] }}</strong>
                                            @if (!empty($payment['reference']))
                                                <small>{{ $payment['reference'] }}</small>
                                            @endif
                                        </span>
                                        <strong>{{ $payment['amount'] }}</strong>
                                        <em class="is-{{ $payment['status_tone'] }}">{{ $payment['status'] }}</em>
                                        @if (!empty($payment['document_url']))
                                            <a href="{{ $payment['document_url'] }}" class="account-icon-btn pro-billing-table__doc" aria-label="Завантажити документ">
                                                <i class="fa-solid fa-download" aria-hidden="true"></i>
                                            </a>
                                        @else
                                            <button type="button" class="account-icon-btn" disabled aria-label="Документ ще недоступний">
                                                <i class="fa-solid fa-download" aria-hidden="true"></i>
                                            </button>
                                        @endif
                                    </div>
                                @empty
                                    <p class="account-empty">Платежів для цього профілю ще немає.</p>
                                @endforelse
                            </div>
                        </section>
                    </section>
                    </div>
                @endif

                @if ($currentProfile)
                    <div class="pro-account-tab-stack" data-pro-tab-panel="profile" @if ($tab !== 'profile') hidden @endif>
                    {{-- Повне редагування профілю (фото, послуги, контакти, опис,
                         досьє, SEO, публікація) — лише з PRO. Без підписки власник
                         змінює тільки назву (гілка @else нижче). Аналітику видно
                         безкоштовно на вкладці «Огляд». --}}
                    @if ($canEditFullProfile ?? false)
                    <section class="pro-profile-editor">
                        <div class="pro-profile-editor__hero">
                            <div class="pro-profile-editor__hero-copy">
                                <h2>Профіль</h2>
                                <p>Редагуйте інформацію про вашу компанію, щоб клієнти могли легко знайти вас.</p>
                            </div>

                            <div class="pro-profile-editor__hero-actions">
                                @if ($previewPublicUrl)
                                    <a class="btn btn--ghost account-btn--mini" href="{{ $previewPublicUrl }}" target="_blank" rel="noopener noreferrer">
                                        <i class="fa-regular fa-eye" aria-hidden="true"></i>
                                        <span>Переглянути</span>
                                    </a>
                                @endif
                                <button type="submit" form="pro-profile-form" name="publish_profile" value="1" class="btn btn--primary account-btn--mini">
                                    <i class="fa-regular fa-paper-plane" aria-hidden="true"></i>
                                    <span>Опублікувати</span>
                                </button>
                            </div>
                        </div>

                        <div class="pro-profile-layout">
                            <form method="POST" action="{{ route('pro.account.profiles.update', $currentProfile) }}" class="card account-block account-form pro-account-form pro-profile-form pro-profile-form-card" id="pro-profile-form" data-ajax-profile-form>
                                @csrf
                                @method('PATCH')

                                <section class="pro-profile-section">
                                    <div class="pro-profile-section__head">
                                        <h3><i class="fa-solid fa-briefcase" aria-hidden="true"></i> Основна інформація</h3>
                                    </div>

                                    <div class="pro-profile-section__view">
                                        <div class="pro-profile-live-grid">
                                            <label class="pro-profile-live-field">
                                                <span>Назва компанії / ПІБ</span>
                                                <input type="text" name="name" value="{{ old('name', $currentProfile->name) }}" required>
                                            </label>
                                            <label class="pro-profile-live-field">
                                                <span>Категорія</span>
                                                <select name="category_id" data-pro-category>
                                                    <option value="">Оберіть категорію</option>
                                                    @foreach ($categories as $category)
                                                        <option value="{{ $category->id }}" @selected((int) $selectedCategoryId === (int) $category->id)>{{ $category->name }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <label class="pro-profile-live-field">
                                                <span>Підкатегорія</span>
                                                <select name="subcategory_id" data-pro-subcategory>
                                                    <option value="">Оберіть підкатегорію</option>
                                                    @foreach (($categoryChildren[$selectedCategoryId] ?? []) as $subcategory)
                                                        <option value="{{ $subcategory['id'] }}" @selected((int) $selectedSubcategoryId === (int) $subcategory['id'])>{{ $subcategory['name'] }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <label class="pro-profile-live-field">
                                                <span>Місто</span>
                                                <input
                                                    type="text"
                                                    name="city"
                                                    value="{{ old('city', $currentProfile->city) }}"
                                                    placeholder="Одеса"
                                                    list="pro-account-city-list"
                                                    autocomplete="address-level2"
                                                    data-pro-city
                                                >
                                            </label>
                                            <label class="pro-profile-live-field">
                                                <span>Регіон (область)</span>
                                                <select name="region_id" data-pro-region>
                                                    <option value="">Оберіть область</option>
                                                    @foreach ($regions as $region)
                                                        <option value="{{ $region->id }}" @selected($selectedRegionId === (int) $region->id)>{{ $region->name }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                        </div>
                                        <datalist id="pro-account-city-list"></datalist>

                                        <div class="pro-profile-reference-fields">
                                            <label class="pro-profile-reference-field pro-profile-reference-field--wide">
                                                <span>Короткий опис *</span>
                                                <textarea name="short_description" rows="4" maxlength="500" data-profile-description-input placeholder="Коротко поясніть, з чим ви працюєте і чому до вас звертаються.">{{ $shortDescriptionEditValue }}</textarea>
                                                <small class="pro-profile-field-meta"><span>Показується в картках і у верхній частині профілю.</span><strong data-profile-description-count>{{ $profileDescriptionLength }}/500</strong></small>
                                            </label>

                                            <label class="pro-profile-reference-field pro-profile-reference-field--wide">
                                                <span>Розгорнутий опис</span>
                                                <textarea name="description" rows="5" placeholder="Детальніше про послуги, підхід до роботи, досвід та сильні сторони профілю.">{{ $descriptionEditValue }}</textarea>
                                            </label>

                                            {{-- Textarea досьє прибрано свідомо: досьє (факти з відкритих
                                                 джерел) веде команда, бізнес його не редагує — бачить
                                                 превʼю нижче і може подати запит на перегляд. --}}
                                            @php
                                                $openDossierRevision = \App\Models\DossierRevisionRequest::query()
                                                    ->where('profile_id', $currentProfile->id)
                                                    ->whereIn('status', \App\Models\DossierRevisionRequest::OPEN_STATUSES)
                                                    ->latest('id')
                                                    ->first();

                                                // Превʼю досьє: той самий рендер, що на публічній сторінці.
                                                $cabinetDossierRaw = trim((string) ($currentProfile->dossier ?? ''));
                                                $cabinetDossierHtml = '';
                                                if ($cabinetDossierRaw !== '') {
                                                    try {
                                                        $cabinetDossierHtml = (string) \Illuminate\Support\Str::markdown($cabinetDossierRaw, [
                                                            'html_input' => 'strip',
                                                            'allow_unsafe_links' => false,
                                                            'max_nesting_level' => 6,
                                                        ]);
                                                        $cabinetDossierHtml = preg_replace(
                                                            ['/<blockquote>\s*<p>\s*🔴\s*/u', '/<blockquote>\s*<p>\s*⚠️?\s*/u', '/<blockquote>\s*<p>\s*✅\s*/u'],
                                                            [
                                                                '<blockquote class="dossier-callout dossier-callout--critical"><p>',
                                                                '<blockquote class="dossier-callout dossier-callout--warning"><p>',
                                                                '<blockquote class="dossier-callout dossier-callout--positive"><p>',
                                                            ],
                                                            $cabinetDossierHtml
                                                        );
                                                    } catch (\Throwable $e) {
                                                        $cabinetDossierHtml = '';
                                                    }
                                                }
                                                $cabinetDossierVerdict = in_array($currentProfile->dossier_verdict, ['red', 'yellow', 'green'], true)
                                                    ? $currentProfile->dossier_verdict
                                                    : null;
                                                $cabinetDossierVerdictMeta = [
                                                    'red' => ['label' => 'Є критичні факти', 'bg' => '#fbe8e5', 'fg' => '#b3261e'],
                                                    'yellow' => ['label' => 'Є що перевірити', 'bg' => '#f8eede', 'fg' => '#9a6a12'],
                                                    'green' => ['label' => 'Записи чисті', 'bg' => '#e2f7ec', 'fg' => '#15803d'],
                                                ];
                                            @endphp
                                            {{-- Досьє в кабінеті: превʼю (read-only, той самий рендер, що
                                                 публічно) + запит на перегляд. Бізнес факти не редагує —
                                                 описує, що неточне, а досьє переробляє команда. --}}
                                            @if ($cabinetDossierHtml !== '')
                                            <div class="pro-profile-reference-field pro-profile-reference-field--wide dossier-rev" data-dossier-revision data-dossier-revision-url="{{ route('pro.account.profiles.dossier-revision', $currentProfile) }}">
                                                <style>
                                                    .dossier-rev { padding: 0; border: 1px solid var(--c-border, rgba(15,23,42,.1)); border-radius: 16px; background: var(--c-surface, #fff); overflow: hidden; }
                                                    .dossier-rev [hidden] { display: none !important; }
                                                    .dossier-rev__head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 15px 18px; border-bottom: 1px solid var(--c-border, rgba(15,23,42,.08)); background: #f8fafd; }
                                                    .dossier-rev__icon { flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 11px; background: #e8f0ff; color: var(--c-primary, #2563eb); }
                                                    .dossier-rev__icon svg { width: 18px; height: 18px; }
                                                    .dossier-rev__head b { font-size: .98rem; color: var(--c-heading, #1e293b); }
                                                    .dossier-rev__verdict { display: inline-flex; align-items: center; padding: 3px 11px; border-radius: 999px; font-size: .76rem; font-weight: 750; }
                                                    .dossier-rev__date { margin-left: auto; color: var(--c-muted, #94a3b8); font-size: .8rem; }
                                                    .dossier-rev__body { position: relative; padding: 4px 18px 8px; color: var(--c-text, #334155); font-size: .92rem; line-height: 1.62; }
                                                    .dossier-rev__body.is-collapsed { max-height: 250px; overflow: hidden; }
                                                    .dossier-rev__body.is-collapsed::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 70px; background: linear-gradient(180deg, rgba(255,255,255,0), var(--c-surface, #fff)); pointer-events: none; }
                                                    .dossier-rev__body h1, .dossier-rev__body h2, .dossier-rev__body h3 { font-size: 1rem; margin: 14px 0 6px; color: var(--c-heading, #1e293b); }
                                                    .dossier-rev__body blockquote { margin: 10px 0; padding: 10px 14px; border-left: 4px solid var(--c-border, #cbd5e1); border-radius: 8px; background: #f6f8fc; }
                                                    .dossier-rev__body blockquote p { margin: 0; }
                                                    .dossier-rev__body .dossier-callout--critical { border-left-color: #dc2626; background: #fbe8e5; }
                                                    .dossier-rev__body .dossier-callout--warning { border-left-color: #d97706; background: #f8eede; }
                                                    .dossier-rev__body .dossier-callout--positive { border-left-color: #16a34a; background: #e2f7ec; }
                                                    .dossier-rev__toggle { display: block; margin: 0 18px 6px; padding: 6px 0; border: 0; background: transparent; color: var(--c-primary, #2563eb); font: inherit; font-size: .88rem; font-weight: 700; cursor: pointer; }
                                                    .dossier-rev__note { margin: 0 18px 12px; color: var(--c-muted, #94a3b8); font-size: .78rem; }
                                                    .dossier-rev__foot { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding: 13px 18px; border-top: 1px solid var(--c-border, rgba(15,23,42,.08)); background: #f8fafd; }
                                                    .dossier-rev__foot-text { min-width: 0; }
                                                    .dossier-rev__foot-text b { display: block; font-size: .9rem; color: var(--c-heading, #1e293b); }
                                                    .dossier-rev__foot-text p { margin: 1px 0 0; font-size: .82rem; color: var(--c-muted, #64748b); }
                                                    .dossier-rev__open { flex: 0 0 auto; margin-left: auto; display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px; border: 1.5px solid var(--c-primary, #2563eb); border-radius: 11px; background: var(--c-surface, #fff); color: var(--c-primary, #2563eb); font: inherit; font-size: .88rem; font-weight: 750; cursor: pointer; transition: background .12s ease, color .12s ease; }
                                                    .dossier-rev__open:hover { background: var(--c-primary, #2563eb); color: #fff; }
                                                    .dossier-rev__open svg { width: 14px; height: 14px; }
                                                    .dossier-rev__form { padding: 14px 18px 16px; border-top: 1px solid var(--c-border, rgba(15,23,42,.08)); background: #f8fafd; }
                                                    .dossier-rev__form textarea { width: 100%; box-sizing: border-box; }
                                                    .dossier-rev__actions { display: flex; gap: 12px; align-items: center; margin-top: 10px; }
                                                    .dossier-rev__cancel { border: 0; background: transparent; color: var(--c-muted, #64748b); font: inherit; font-size: .87rem; font-weight: 650; cursor: pointer; }
                                                    .dossier-rev__cancel:hover { color: var(--c-heading, #1e293b); }
                                                    .dossier-rev__sent { display: flex; gap: 10px; align-items: flex-start; margin: 0; padding: 13px 18px; border-top: 1px solid rgba(22,163,74,.25); background: #e2f7ec; color: #166534; font-size: .9rem; line-height: 1.5; }
                                                    .dossier-rev__error { margin: 8px 0 0; color: #be123c; font-size: .86rem; }
                                                </style>

                                                <div class="dossier-rev__head">
                                                    <span class="dossier-rev__icon" aria-hidden="true">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.5 4.5 5.4v5.4c0 4.6 3.2 8.6 7.5 9.9 4.3-1.3 7.5-5.3 7.5-9.9V5.4L12 2.5Z"/><path d="m9 11.8 2.1 2.1 4-4.2"/></svg>
                                                    </span>
                                                    <b>Досьє профілю</b>
                                                    @if ($cabinetDossierVerdict)
                                                        <span class="dossier-rev__verdict" style="background: {{ $cabinetDossierVerdictMeta[$cabinetDossierVerdict]['bg'] }}; color: {{ $cabinetDossierVerdictMeta[$cabinetDossierVerdict]['fg'] }};">{{ $cabinetDossierVerdictMeta[$cabinetDossierVerdict]['label'] }}</span>
                                                    @endif
                                                    @if ($currentProfile->dossier_generated_at)
                                                        <span class="dossier-rev__date">Оновлено {{ $currentProfile->dossier_generated_at->translatedFormat('d F Y') }}</span>
                                                    @endif
                                                </div>

                                                <div class="dossier-rev__body is-collapsed" data-dossier-preview>{!! $cabinetDossierHtml !!}</div>
                                                <button type="button" class="dossier-rev__toggle" data-dossier-preview-toggle>Показати повністю</button>
                                                <p class="dossier-rev__note">Так досьє бачать відвідувачі публічної сторінки. Редагується воно лише командою Dovira.</p>

                                                @if ($openDossierRevision)
                                                    <div class="dossier-rev__foot">
                                                        <div class="dossier-rev__foot-text">
                                                            <b>Запит на перегляд досьє на розгляді</b>
                                                            <p>Надіслано {{ $openDossierRevision->created_at->translatedFormat('d F Y') }} · {{ $openDossierRevision->status === \App\Models\DossierRevisionRequest::STATUS_IN_REVIEW ? 'вже в роботі' : 'очікує перевірки' }}. Про результат сповістимо в кабінеті.</p>
                                                        </div>
                                                    </div>
                                                @else
                                                    <div class="dossier-rev__foot" data-dossier-revision-intro>
                                                        <div class="dossier-rev__foot-text">
                                                            <b>Помітили неточність?</b>
                                                            <p>Якщо факт застарів чи не відповідає дійсності — ми перевіримо за першоджерелами й оновимо.</p>
                                                        </div>
                                                        <button type="button" class="dossier-rev__open" data-dossier-revision-open>
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                                            <span>Повідомити про неточність</span>
                                                        </button>
                                                    </div>

                                                    <div class="dossier-rev__form" data-dossier-revision-form hidden>
                                                        <textarea rows="4" maxlength="3000" placeholder="Опишіть, що саме не відповідає дійсності: який факт, чому він неточний, як є насправді. Посилання на підтвердження пришвидшать перевірку." data-dossier-revision-message></textarea>
                                                        <span class="pro-profile-field-meta">Ми перевіримо факти за першоджерелами й оновимо досьє. Про результат сповістимо в кабінеті.</span>
                                                        <p class="dossier-rev__error" data-dossier-revision-error hidden></p>
                                                        <div class="dossier-rev__actions">
                                                            <button type="button" class="btn btn--primary account-btn--mini" data-dossier-revision-submit>
                                                                <span data-dossier-revision-submit-label>Надіслати на перевірку</span>
                                                            </button>
                                                            <button type="button" class="dossier-rev__cancel" data-dossier-revision-cancel>Скасувати</button>
                                                        </div>
                                                    </div>

                                                    <div class="dossier-rev__sent" data-dossier-revision-sent hidden>
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex:0 0 auto; width:16px; height:16px; margin-top:2px;"><path d="m4.5 12.5 5 5 10-11"/></svg>
                                                        <span>Запит надіслано. Ми перевіримо факти за першоджерелами — про результат сповістимо в кабінеті.</span>
                                                    </div>
                                                @endif
                                            </div>
                                            @endif
                                        </div>
                                    </div>
                                </section>

                                <section class="pro-profile-section" id="pro-profile-contacts-section">
                                    <div class="pro-profile-section__head">
                                        <h3><i class="fa-solid fa-envelope" aria-hidden="true"></i> Контакти</h3>
                                    </div>

                                    <div class="pro-profile-section__view">
                                        <div class="pro-profile-live-grid">
                                            <label class="pro-profile-live-field">
                                                <span>Телефон</span>
                                                <input type="text" name="phone" value="{{ old('phone', $currentProfile->phone) }}" data-phone-mask inputmode="tel" autocomplete="tel">
                                            </label>
                                            <label class="pro-profile-live-field">
                                                <span>Email</span>
                                                <input type="email" name="email" value="{{ old('email', $currentProfile->email) }}">
                                            </label>
                                            <label class="pro-profile-live-field">
                                                <span>Вебсайт</span>
                                                <input type="url" name="website" value="{{ old('website', $currentProfile->website) }}">
                                            </label>
                                            <label class="pro-profile-live-field">
                                                <span>Адреса</span>
                                                <input type="text" name="address" value="{{ old('address', $currentProfile->address) }}">
                                            </label>
                                        </div>

                                        <div class="pro-profile-live-field pro-profile-live-field--wide" data-cta-mode-field>
                                            <span>Кнопка звʼязку (CTA)</span>
                                            @php $ctaMode = old('contact_cta_mode', $currentProfile->contact_cta_mode ?? 'link'); @endphp
                                            <div class="pro-cta-mode-switch" role="radiogroup" aria-label="Куди веде кнопка звʼязку">
                                                <label class="pro-cta-mode-switch__option {{ $ctaMode !== 'lead_form' ? 'is-active' : '' }}">
                                                    <input type="radio" name="contact_cta_mode" value="link" @checked($ctaMode !== 'lead_form') data-cta-mode-radio>
                                                    <i class="fa-solid fa-link" aria-hidden="true"></i>
                                                    <span>Моє посилання</span>
                                                </label>
                                                <label class="pro-cta-mode-switch__option {{ $ctaMode === 'lead_form' ? 'is-active' : '' }}">
                                                    <input type="radio" name="contact_cta_mode" value="lead_form" @checked($ctaMode === 'lead_form') data-cta-mode-radio>
                                                    <i class="fa-regular fa-paper-plane" aria-hidden="true"></i>
                                                    <span>Форма заявки</span>
                                                </label>
                                            </div>
                                            <div data-cta-url-field @if ($ctaMode === 'lead_form') hidden @endif>
                                                <input type="text" name="contact_cta_url" value="{{ old('contact_cta_url', $currentProfile->contact_cta_url) }}" placeholder="t.me/nickname, wa.me/380..., instagram.com/... або https://...">
                                                <small class="pro-account-field-note">Вставте будь-яке посилання — Telegram, WhatsApp, Viber, Instagram, Facebook, форму запису чи сайт. У контактах профілю зʼявиться помітна кнопка з правильною іконкою та назвою.</small>
                                            </div>
                                            <small class="pro-account-field-note" data-cta-lead-note @if ($ctaMode !== 'lead_form') hidden @endif>
                                                Кнопка «Залишити заявку» відкриватиме форму: клієнт лишає імʼя і телефон, а заявка падає у вкладку «Заявки» вашого кабінету та на email.
                                            </small>
                                        </div>
                                    </div>
                                </section>

                                <section class="pro-profile-section">
                                    <div class="pro-profile-section__head">
                                        <h3><i class="fa-regular fa-id-card" aria-hidden="true"></i> Інформація у вкладці профілю</h3>
                                    </div>

                                    <div class="pro-profile-section__view">
                                        <div class="pro-profile-info-note">
                                            <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>
                                            <div>
                                                <strong>Заповнюйте тільки те, що хочете показувати публічно.</strong>
                                                <p>Порожні блоки на сторінці профілю не показуються. Фото для галереї керуються в блоці “Медіа” нижче.</p>
                                            </div>
                                        </div>

                                        <div class="pro-profile-info-stats">
                                            <label class="pro-profile-info-stat">
                                                <span>Років досвіду</span>
                                                <input type="number" min="0" max="99" name="owner_profile_experience_years" value="{{ old('owner_profile_experience_years', $proProfileData->get('experience_years')) }}" placeholder="7">
                                                <small>Покажеться як “7+ років досвіду”.</small>
                                            </label>
                                            <label class="pro-profile-info-stat">
                                                <span>Кількість консультацій</span>
                                                <input type="number" min="0" max="999999" name="owner_profile_consultations_count" value="{{ old('owner_profile_consultations_count', $proProfileData->get('consultations_count')) }}" placeholder="1190">
                                                <small>Цифра без додаткового тексту.</small>
                                            </label>
                                            <label class="pro-profile-info-stat">
                                                <span>Швидкість відповіді</span>
                                                <input type="text" maxlength="60" name="owner_profile_response_speed" value="{{ old('owner_profile_response_speed', $proProfileData->get('response_speed')) }}" placeholder="Швидко">
                                                <small>Короткий акцент: “Швидко”, “до 15 хв”, “в день звернення”.</small>
                                            </label>
                                        </div>

                                        <div class="pro-profile-reference-fields">
                                            <label class="pro-profile-reference-field pro-profile-reference-field--wide">
                                                <span>Досвід та кваліфікація</span>
                                                <textarea name="owner_profile_experience" rows="5" placeholder="Освіта, стаж, напрямки практики, типові кейси — все, що підтверджує вашу експертність.">{{ old('owner_profile_experience', $proProfileData->get('experience')) }}</textarea>
                                            </label>
                                        </div>

                                        <div class="pro-profile-info-grid">
                                            <div class="pro-profile-summary-card pro-profile-summary-card--wide pro-profile-json-card">
                                                <div class="pro-profile-summary-card__top">
                                                    <span>FAQ</span>
                                                </div>
                                                <div class="pro-profile-json-editor" data-pro-json-repeater data-target-name="owner_profile_faq_json" data-max-items="10">
                                                    <input type="hidden" name="owner_profile_faq_json" value="{{ $ownerProfileFaqJson }}">
                                                    <div class="pro-profile-json-list" data-repeater-list>
                                                        @foreach ($ownerProfileFaq as $faqItem)
                                                            <article class="pro-profile-json-item" data-repeater-item>
                                                                <div class="pro-profile-json-item__head">
                                                                    <strong>Питання {{ $loop->iteration }}</strong>
                                                                    <button type="button" class="pro-profile-json-remove" data-repeater-remove aria-label="Видалити питання">
                                                                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                                                    </button>
                                                                </div>
                                                                <div class="pro-profile-json-fields">
                                                                    <label class="pro-profile-json-fields__wide">
                                                                        <span>Питання</span>
                                                                        <input type="text" value="{{ $faqItem['q'] ?? ($faqItem['question'] ?? '') }}" maxlength="180" data-repeater-key="q" placeholder="Що найчастіше питають клієнти?">
                                                                    </label>
                                                                    <label class="pro-profile-json-fields__wide">
                                                                        <span>Відповідь</span>
                                                                        <textarea rows="4" maxlength="1200" data-repeater-key="a" placeholder="Коротка й зрозуміла відповідь.">{{ $faqItem['a'] ?? ($faqItem['answer'] ?? '') }}</textarea>
                                                                    </label>
                                                                </div>
                                                            </article>
                                                        @endforeach
                                                    </div>
                                                    <div class="pro-profile-json-empty" data-repeater-empty @if ($ownerProfileFaq->isNotEmpty()) hidden @endif>Ще немає жодного питання.</div>
                                                    <button type="button" class="pro-profile-json-add" data-repeater-add>
                                                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
                                                        <span>Додати питання</span>
                                                    </button>
                                                    <template data-repeater-template>
                                                        <article class="pro-profile-json-item" data-repeater-item>
                                                            <div class="pro-profile-json-item__head">
                                                                <strong>Нове питання</strong>
                                                                <button type="button" class="pro-profile-json-remove" data-repeater-remove aria-label="Видалити питання">
                                                                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                                                </button>
                                                            </div>
                                                            <div class="pro-profile-json-fields">
                                                                <label class="pro-profile-json-fields__wide">
                                                                    <span>Питання</span>
                                                                    <input type="text" value="" maxlength="180" data-repeater-key="q" placeholder="Скільки триває консультація?">
                                                                </label>
                                                                <label class="pro-profile-json-fields__wide">
                                                                    <span>Відповідь</span>
                                                                    <textarea rows="4" maxlength="1200" data-repeater-key="a" placeholder="Опишіть відповідь простою мовою."></textarea>
                                                                </label>
                                                            </div>
                                                        </article>
                                                    </template>
                                                </div>
                                            </div>

                                            <div class="pro-profile-summary-card pro-profile-summary-card--wide pro-profile-json-card">
                                                <div class="pro-profile-summary-card__top">
                                                    <span>Кейси</span>
                                                </div>
                                                {{-- Підказка зі зразком: без неї поле «Назва кейсу» власник
                                                     заповнює загальними фразами, і блок втрачає сенс. --}}
                                                <div class="pro-cases-hint">
                                                    <p class="pro-cases-hint__title">Кейс — це одна історія клієнта в трьох рядках</p>
                                                    <ol class="pro-cases-hint__steps">
                                                        <li><b>Назва</b> — що саме зробили: «Виплата 15 млн: суд скасував відмову через алкоголь у крові загиблого»</li>
                                                        <li><b>З чим прийшов клієнт</b> — ситуація на старті: «Родині відмовили у виплаті за загиблого військового»</li>
                                                        <li><b>Результат</b> — чим закінчилось, із цифрою або строком: «Суд зобовʼязав виплатити 15 мільйонів гривень»</li>
                                                    </ol>
                                                    <p class="pro-cases-hint__note">
                                                        Найсильніше працює коротке відео на 30–60 секунд, зняте на телефон: вставте посилання на файл або на YouTube.
                                                        Живий приклад — кейси <a href="{{ route('profile.show', ['slug' => 'smirnov-andrii-igorovic']) }}?tab=info#profile-cases" target="_blank" rel="noopener">Андрія Смирнова</a>.
                                                    </p>
                                                    <details class="pro-cases-hint__more">
                                                        <summary>Ще дві поради</summary>
                                                        <p class="pro-cases-hint__note">
                                                            Якщо про цю справу є відгук клієнта — попросіть нас привʼязати його до кейсу: під кейсом зʼявиться цитата, і його можна буде перевірити.
                                                        </p>
                                                        <p class="pro-cases-hint__note pro-cases-hint__note--muted">
                                                            Не пишіть «професійний підхід» та обіцянки результату наперед і не вказуйте персональні дані клієнта.
                                                        </p>
                                                    </details>
                                                </div>
                                                <div class="pro-profile-json-editor" data-pro-json-repeater data-target-name="owner_profile_cases_json" data-max-items="10" data-item-label="Кейс">
                                                    <input type="hidden" name="owner_profile_cases_json" value="{{ $ownerProfileCasesJson }}">
                                                    <div class="pro-profile-json-list" data-repeater-list>
                                                        @foreach ($ownerProfileCases as $caseItem)
                                                            <article class="pro-profile-json-item" data-repeater-item>
                                                                <div class="pro-profile-json-item__head">
                                                                    <strong>Кейс {{ $loop->iteration }}</strong>
                                                                    <button type="button" class="pro-profile-json-remove" data-repeater-remove aria-label="Видалити кейс">
                                                                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                                                    </button>
                                                                </div>
                                                                <div class="pro-profile-json-fields">
                                                                    <label class="pro-profile-json-fields__wide">
                                                                        <span>Назва кейсу</span>
                                                                        <input type="text" value="{{ $caseItem['title'] ?? '' }}" maxlength="120" data-repeater-key="title" placeholder="Стягнення боргу за договором підряду">
                                                                    </label>
                                                                    <label class="pro-profile-json-fields__wide">
                                                                        <span>З чим прийшов клієнт</span>
                                                                        <textarea rows="3" maxlength="400" data-repeater-key="task" placeholder="Коротко опишіть ситуацію клієнта на старті.">{{ $caseItem['task'] ?? '' }}</textarea>
                                                                    </label>
                                                                    <label class="pro-profile-json-fields__wide">
                                                                        <span>Результат</span>
                                                                        <textarea rows="3" maxlength="400" data-repeater-key="result" placeholder="Чим закінчилось. Конкретика переконує більше за емоції.">{{ $caseItem['result'] ?? '' }}</textarea>
                                                                    </label>
                                                                    <label>
                                                                        <span>Напрям</span>
                                                                        <input type="text" value="{{ $caseItem['tag'] ?? '' }}" maxlength="60" data-repeater-key="tag" placeholder="Господарські спори">
                                                                    </label>
                                                                    <label>
                                                                        <span>Скільки тривало</span>
                                                                        <input type="text" value="{{ $caseItem['period'] ?? '' }}" maxlength="40" data-repeater-key="period" placeholder="3 місяці">
                                                                    </label>
                                                                    <label class="pro-profile-json-fields__wide">
                                                                        <span>Посилання (необовʼязково)</span>
                                                                        <input type="url" value="{{ $caseItem['url'] ?? '' }}" maxlength="300" data-repeater-key="url" placeholder="https://...">
                                                                    </label>
                                                                    <label class="pro-profile-json-fields__wide">
                                                                        <span>Відео: YouTube або пряме посилання на файл</span>
                                                                        <input type="url" value="{{ $caseItem['video'] ?? '' }}" maxlength="300" data-repeater-key="video" placeholder="https://youtu.be/... або https://site.com/video.mp4">
                                                                    </label>
                                                                    <label class="pro-profile-json-fields__wide">
                                                                        <span>Фото: посилання на зображення</span>
                                                                        <input type="url" value="{{ $caseItem['image'] ?? '' }}" maxlength="300" data-repeater-key="image" placeholder="https://site.com/photo.jpg">
                                                                    </label>
                                                                    <input type="hidden" value="{{ $caseItem['review_id'] ?? '' }}" data-repeater-key="review_id">
                                                                    <input type="hidden" value="{{ $caseItem['review_date'] ?? '' }}" data-repeater-key="review_date">
                                                                </div>
                                                            </article>
                                                        @endforeach
                                                    </div>
                                                    <div class="pro-profile-json-empty" data-repeater-empty @if ($ownerProfileCases->isNotEmpty()) hidden @endif>Ще немає жодного кейсу.</div>
                                                    <button type="button" class="pro-profile-json-add" data-repeater-add>
                                                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
                                                        <span>Додати кейс</span>
                                                    </button>
                                                    <template data-repeater-template>
                                                        <article class="pro-profile-json-item" data-repeater-item>
                                                            <div class="pro-profile-json-item__head">
                                                                <strong>Новий кейс</strong>
                                                                <button type="button" class="pro-profile-json-remove" data-repeater-remove aria-label="Видалити кейс">
                                                                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                                                </button>
                                                            </div>
                                                            <div class="pro-profile-json-fields">
                                                                <label class="pro-profile-json-fields__wide">
                                                                    <span>Назва кейсу</span>
                                                                    <input type="text" value="" maxlength="120" data-repeater-key="title" placeholder="Стягнення боргу за договором підряду">
                                                                </label>
                                                                <label class="pro-profile-json-fields__wide">
                                                                    <span>З чим прийшов клієнт</span>
                                                                    <textarea rows="3" maxlength="400" data-repeater-key="task" placeholder="Коротко опишіть ситуацію клієнта на старті."></textarea>
                                                                </label>
                                                                <label class="pro-profile-json-fields__wide">
                                                                    <span>Результат</span>
                                                                    <textarea rows="3" maxlength="400" data-repeater-key="result" placeholder="Чим закінчилось. Конкретика переконує більше за емоції."></textarea>
                                                                </label>
                                                                <label>
                                                                    <span>Напрям</span>
                                                                    <input type="text" value="" maxlength="60" data-repeater-key="tag" placeholder="Господарські спори">
                                                                </label>
                                                                <label>
                                                                    <span>Скільки тривало</span>
                                                                    <input type="text" value="" maxlength="40" data-repeater-key="period" placeholder="3 місяці">
                                                                </label>
                                                                <label class="pro-profile-json-fields__wide">
                                                                    <span>Посилання (необовʼязково)</span>
                                                                    <input type="url" value="" maxlength="300" data-repeater-key="url" placeholder="https://...">
                                                                </label>
                                                                <label class="pro-profile-json-fields__wide">
                                                                    <span>Відео: YouTube або пряме посилання на файл</span>
                                                                    <input type="url" value="" maxlength="300" data-repeater-key="video" placeholder="https://youtu.be/... або https://site.com/video.mp4">
                                                                </label>
                                                                <label class="pro-profile-json-fields__wide">
                                                                    <span>Фото: посилання на зображення</span>
                                                                    <input type="url" value="" maxlength="300" data-repeater-key="image" placeholder="https://site.com/photo.jpg">
                                                                </label>
                                                            </div>
                                                        </article>
                                                    </template>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </section>

                                <section class="pro-profile-section">
                                    <div class="pro-profile-section__head">
                                        <h3><i class="fa-solid fa-layer-group" aria-hidden="true"></i> Послуги</h3>
                                    </div>

                                    <div class="pro-profile-summary-card pro-profile-summary-card--wide">
                                        <div class="pro-profile-summary-card__top">
                                            <span>Напрями / послуги</span>
                                        </div>
                                        <div class="pro-profile-card-edit-panel pro-profile-card-edit-panel--always-open">
                                            <div class="pro-profile-service-editor" data-service-editor>
                                                <div class="pro-profile-service-input-shell" data-service-shell>
                                                    <div class="pro-profile-service-inline" data-service-list>
                                                        @foreach ($selectedServiceNames as $serviceName)
                                                            <span class="pro-profile-service-tag" data-service-chip>
                                                                <span>{{ $serviceName }}</span>
                                                                <button type="button" class="pro-profile-service-tag__remove" data-service-remove aria-label="Видалити {{ $serviceName }}">
                                                                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                                                </button>
                                                                <input type="hidden" name="service_names[]" value="{{ $serviceName }}">
                                                            </span>
                                                        @endforeach
                                                        <span class="pro-profile-service-entry" data-service-entry-wrap hidden>
                                                            <input
                                                                type="text"
                                                                class="pro-profile-service-entry__input"
                                                                data-service-entry
                                                                placeholder="Назва послуги"
                                                            >
                                                            <div class="pro-profile-service-suggestions" data-service-suggestions hidden></div>
                                                        </span>
                                                        <button type="button" class="pro-profile-service-add" data-service-add>
                                                            <i class="fa-solid fa-plus" aria-hidden="true"></i>
                                                            <span>Додати послугу</span>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>

                                            @php
                                                // Наявний прайс для JS-редактора: {назва: {type, price, price_max, description}}.
                                                $servicePriceRows = ($currentProfile?->services ?? collect())
                                                    ->mapWithKeys(fn ($svc) => [$svc->name => [
                                                        'type' => $svc->pivot->price_type,
                                                        'price' => $svc->pivot->price,
                                                        'price_max' => $svc->pivot->price_max,
                                                        'description' => $svc->pivot->description,
                                                        'sort' => (int) $svc->pivot->sort_order,
                                                    ]]);
                                            @endphp
                                            {{-- Прайс: рядки будує pro.js із чипів вище — назва завжди
                                                 синхронна з набором послуг. Мінімалізм: тип, ціна, опис. --}}
                                            <div class="pro-service-prices" data-service-prices data-existing='@json($servicePriceRows)'>
                                                <style>
                                                    .pro-service-prices { margin-top: 14px; }
                                                    .pro-service-prices__head { margin: 0 0 2px; font-size: .92rem; font-weight: 750; color: var(--c-heading, #1e293b); }
                                                    .pro-service-prices__hint { margin: 0 0 10px; font-size: .8rem; color: var(--c-muted, #64748b); }
                                                    .pro-service-prices__row { display: grid; grid-template-columns: minmax(140px, 1.2fr) 130px 92px 92px minmax(160px, 1.6fr); gap: 8px; align-items: center; padding: 7px 0; border-top: 1px solid var(--c-border, rgba(15,23,42,.07)); }
                                                    .pro-service-prices__row:first-of-type { border-top: 0; }
                                                    .pro-service-prices__name { font-size: .88rem; font-weight: 650; color: var(--c-heading, #1e293b); overflow-wrap: anywhere; }
                                                    .pro-service-prices__row select,
                                                    .pro-service-prices__row input { width: 100%; box-sizing: border-box; height: 36px; padding: 0 9px; border: 1px solid var(--c-border, rgba(15,23,42,.14)); border-radius: 9px; background: var(--c-surface, #fff); font: inherit; font-size: .85rem; }
                                                    .pro-service-prices__row input[data-price-max][hidden] { display: none; }
                                                    .pro-service-prices__row input:disabled { background: #f1f4f9; color: #94a3b8; }
                                                    .pro-service-prices__empty { padding: 8px 0 2px; font-size: .84rem; color: var(--c-muted, #94a3b8); }
                                                    @media (max-width: 700px) {
                                                        .pro-service-prices__row { grid-template-columns: 1fr 1fr; }
                                                        .pro-service-prices__name { grid-column: 1 / -1; }
                                                        .pro-service-prices__row input[data-price-desc] { grid-column: 1 / -1; }
                                                    }
                                                </style>
                                                <p class="pro-service-prices__head">Ціни та описи</p>
                                                <p class="pro-service-prices__hint">Профілі з цінами отримують більше заявок: ціна видна на сторінці, у каталозі й у Google. Не хочете вказувати суму — оберіть «договірна».</p>
                                                <div data-service-prices-list></div>
                                            </div>
                                        </div>
                                    </div>
                                </section>

                                <section class="pro-profile-section">
                                    <div class="pro-profile-section__head">
                                        <h3><i class="fa-brands fa-instagram" aria-hidden="true"></i> Соцмережі</h3>
                                    </div>

                                    <div class="pro-profile-summary-card pro-profile-summary-card--wide">
                                        <div class="pro-profile-card-edit-panel pro-profile-card-edit-panel--always-open">
                                            <div class="pro-profile-social-manager" data-social-manager>
                                            <div class="pro-profile-social-list" data-social-list>
                                                @foreach ($socialNetworkOptions as $networkKey => $network)
                                                    @php $socialValue = (string) ($socialInputs[$networkKey] ?? ''); @endphp
                                                    <div class="pro-profile-social-row {{ filled($socialValue) ? 'is-active' : 'is-empty' }}" data-social-row="{{ $networkKey }}" data-social-label="{{ $network['label'] }}">
                                                        <button type="button" class="pro-profile-social-add-card" data-social-add="{{ $networkKey }}" aria-label="Додати {{ $network['label'] }}">
                                                            <span class="pro-profile-social-row__icon" aria-hidden="true">
                                                                <i class="{{ $network['icon'] }}" aria-hidden="true"></i>
                                                            </span>
                                                            <span>{{ $network['label'] }}</span>
                                                            <i class="fa-solid fa-plus" aria-hidden="true"></i>
                                                        </button>
                                                        <div class="pro-profile-social-control">
                                                            <span class="pro-profile-social-row__icon" aria-hidden="true">
                                                                <i class="{{ $network['icon'] }}" aria-hidden="true"></i>
                                                            </span>
                                                            <input
                                                                type="url"
                                                                name="{{ $network['field'] }}"
                                                                value="{{ $socialValue }}"
                                                                placeholder="{{ $network['placeholder'] }}"
                                                                data-social-input
                                                            >
                                                            <button type="button" class="account-icon-btn pro-profile-social-remove" data-social-remove="{{ $networkKey }}" aria-label="Видалити {{ $network['label'] }}">
                                                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                            </div>
                                        </div>
                                    </div>
                                </section>

                                <section class="pro-profile-section">
                                    <div class="pro-profile-section__head">
                                        <h3><i class="fa-regular fa-images" aria-hidden="true"></i> Медіа</h3>
                                    </div>

                                    <div class="pro-profile-summary-card pro-profile-summary-card--wide">
                                        <div class="pro-profile-media-manager" data-media-manager>
                                            <div class="pro-profile-media-grid" data-media-grid>
                                                <div class="pro-profile-media-add-card pro-profile-media-add-card--avatar" data-media-avatar-card>
                                                    <button type="button" class="pro-profile-media-add-card__trigger" data-media-avatar-add aria-label="Завантажити аватарку">
                                                        <span class="pro-profile-media-add-card__icon" aria-hidden="true">
                                                            <i class="fa-regular fa-user" aria-hidden="true"></i>
                                                        </span>
                                                        <span data-media-avatar-label>{{ $previewLogoUrl ? 'Оновити аватарку' : 'Додати аватарку' }}</span>
                                                    </button>
                                                    <input
                                                        type="file"
                                                        name="logo_file"
                                                        accept="image/jpeg,image/png,image/webp,image/gif"
                                                        hidden
                                                        data-media-avatar-input
                                                    >
                                                </div>

                                                @if ($previewBannerUrl)
                                                    <article class="pro-profile-media-card" data-media-card data-media-kind="banner">
                                                        <img src="{{ $previewBannerUrl }}" alt="Обкладинка {{ $currentProfile->name }}" loading="lazy">
                                                        <button type="button" class="pro-profile-media-remove" data-media-remove="banner" aria-label="Видалити обкладинку">
                                                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                                        </button>
                                                        <span class="pro-profile-media-badge">Обкладинка</span>
                                                    </article>
                                                @endif

                                                @if ($previewLogoUrl)
                                                    <article class="pro-profile-media-card" data-media-card data-media-kind="logo">
                                                        <img src="{{ $previewLogoUrl }}" alt="Аватарка {{ $currentProfile->name }}" loading="lazy">
                                                        <button type="button" class="pro-profile-media-remove" data-media-remove="logo" aria-label="Видалити аватарку">
                                                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                                        </button>
                                                        <span class="pro-profile-media-badge">Аватарка</span>
                                                    </article>
                                                @endif

                                                @foreach ($previewGalleryItems as $galleryItem)
                                                    <article
                                                        class="pro-profile-media-card{{ ($galleryItem['visible'] ?? true) ? '' : ' is-hidden-media' }}"
                                                        data-media-card
                                                        data-media-kind="gallery"
                                                        data-media-url="{{ $galleryItem['raw'] }}"
                                                        data-media-visible="{{ ($galleryItem['visible'] ?? true) ? '1' : '0' }}"
                                                    >
                                                        <img src="{{ $galleryItem['url'] }}" alt="Фото профілю {{ $currentProfile->name }}" loading="lazy">
                                                        <button type="button" class="pro-profile-media-menu-trigger" data-media-menu-trigger aria-label="Дії з фото" aria-haspopup="true" aria-expanded="false">
                                                            <i class="fa-solid fa-ellipsis" aria-hidden="true"></i>
                                                        </button>
                                                        <div class="pro-profile-media-menu" data-media-menu hidden>
                                                            <button type="button" data-media-toggle-visibility="{{ $galleryItem['raw'] }}" data-media-visible="{{ ($galleryItem['visible'] ?? true) ? '1' : '0' }}">
                                                                <i class="fa-regular {{ ($galleryItem['visible'] ?? true) ? 'fa-eye-slash' : 'fa-eye' }}" aria-hidden="true"></i>
                                                                <span>{{ ($galleryItem['visible'] ?? true) ? 'Приховати' : 'Показати' }}</span>
                                                            </button>
                                                            <button type="button" data-media-make-avatar="{{ $galleryItem['raw'] }}">
                                                                <i class="fa-regular fa-user" aria-hidden="true"></i>
                                                                <span>Зробити аватаркою</span>
                                                            </button>
                                                            <button type="button" class="is-danger" data-media-remove-gallery="{{ $galleryItem['raw'] }}">
                                                                <i class="fa-regular fa-trash-can" aria-hidden="true"></i>
                                                                <span>Видалити фото</span>
                                                            </button>
                                                        </div>
                                                    </article>
                                                @endforeach

                                                <div class="pro-profile-media-add-card" data-media-add-card>
                                                    <button type="button" class="pro-profile-media-add-card__trigger" data-media-add aria-label="Додати фото">
                                                        <span class="pro-profile-media-add-card__icon" aria-hidden="true">
                                                            <i class="fa-solid fa-plus" aria-hidden="true"></i>
                                                        </span>
                                                        <span data-media-add-label>Додати фото</span>
                                                    </button>
                                                    <input
                                                        type="file"
                                                        name="gallery_files[]"
                                                        accept="image/jpeg,image/png,image/webp,image/gif"
                                                        multiple
                                                        hidden
                                                        data-media-file-input
                                                    >
                                                </div>
                                            </div>

                                            <div class="pro-profile-media-source-fields" hidden>
                                                <div class="pro-account-form__grid">
                                                    <label>
                                                        <span>Логотип / avatar URL</span>
                                                        <input type="text" name="logo_url" value="{{ old('logo_url', $currentProfile->logo_url) }}">
                                                    </label>
                                                    <label>
                                                        <span>Банер / cover URL</span>
                                                        <input type="text" name="banner_url" value="{{ old('banner_url', $currentProfile->banner_url) }}">
                                                    </label>
                                                </div>

                                                <label>
                                                    <span>Галерея профілю</span>
                                                    <textarea name="gallery_urls" rows="4" placeholder="По одному URL на рядок">{{ $galleryUrlsValue }}</textarea>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </section>

                                <section class="pro-profile-section pro-profile-section--advanced">
                                    <div class="pro-profile-section__head">
                                        <h3><i class="fa-solid fa-gear" aria-hidden="true"></i> Додаткові налаштування</h3>
                                    </div>

                                    <div class="pro-profile-summary-card pro-profile-summary-card--wide pro-profile-advanced-settings">
                                        <div class="pro-account-form__grid">
                                            <label>
                                                <span>Slug</span>
                                                <input type="text" name="slug" value="{{ old('slug', $currentProfile->slug) }}" required>
                                            </label>
                                            <label>
                                                <span>Статус сторінки</span>
                                                <select name="status">
                                                    @foreach (['active' => 'Опубліковано', 'hidden' => 'Приховано', 'draft' => 'Чернетка'] as $value => $label)
                                                        <option value="{{ $value }}" @selected(old('status', $currentProfile->status) === $value)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <label>
                                                <span>SEO title</span>
                                                <input type="text" name="seo_title" value="{{ old('seo_title', $resolvedSeoTitle) }}">
                                            </label>
                                            {{-- OG image URL прибрано з фронту: картинка для шерингу
                                                 автоматично береться з логотипу профілю. --}}
                                            <label class="pro-account-form__grid-colspan">
                                                <span>SEO description</span>
                                                <textarea name="seo_description" rows="3">{{ old('seo_description', $resolvedSeoDescription) }}</textarea>
                                            </label>
                                        </div>

                                        <label class="account-switch">
                                            <input type="hidden" name="show_in_catalog" value="0">
                                            <input type="checkbox" name="show_in_catalog" value="1" @checked((bool) old('show_in_catalog', $currentProfile->show_in_catalog))>
                                            <span>Показувати в каталозі</span>
                                        </label>
                                    </div>
                                </section>
                            </form>

                            {{-- Липкий бар лишає лише головну дію (Опублікувати/Переглянути).
                                 Статус автозбереження більше не висить постійно — про
                                 збереження повідомляє короткий тост (data-profile-toast). --}}
                            {{-- Липкий бар лишаємо лише для «Опублікувати» (коли профіль
                                 ще не активний). Кнопку «Переглянути» прибрано — перегляд
                                 публічної сторінки доступний із шапки профілю. --}}
                            @if (($currentProfile->status ?? '') !== 'active')
                            <div class="pro-profile-savebar pro-profile-savebar--actions-only" data-profile-savebar>
                                <div class="pro-profile-savebar__actions">
                                    <button type="submit" form="pro-profile-form" name="publish_profile" value="1" class="btn btn--primary account-btn--mini">
                                        <i class="fa-regular fa-paper-plane" aria-hidden="true"></i>
                                        <span>Опублікувати</span>
                                    </button>
                                </div>
                            </div>
                            @endif

                        </div>
                    </section>
                    @else
                    {{-- Без PRO: доступна лише зміна назви + апсел на решту полів. --}}
                    <section class="pro-profile-editor">
                        <div class="pro-profile-editor__hero">
                            <div class="pro-profile-editor__hero-copy">
                                <h2>Профіль</h2>
                                <p>Назву профілю можна змінити безкоштовно. Фото, послуги, контакти, опис і публікація відкриються з PRO.</p>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('pro.account.profiles.update', $currentProfile) }}" class="card account-block account-form pro-account-form pro-profile-form-card">
                            @csrf
                            @method('PATCH')
                            <section class="pro-profile-section">
                                <div class="pro-profile-section__head">
                                    <h3><i class="fa-solid fa-briefcase" aria-hidden="true"></i> Назва профілю</h3>
                                </div>
                                <label class="pro-profile-live-field">
                                    <span>Назва компанії / ПІБ</span>
                                    <input type="text" name="name" value="{{ old('name', $currentProfile->name) }}" required maxlength="255">
                                </label>
                                @error('name')
                                    <p class="account-form__error">{{ $message }}</p>
                                @enderror
                                <div class="pro-profile-editor__hero-actions">
                                    <button type="submit" class="btn btn--primary account-btn--mini">
                                        <i class="fa-regular fa-floppy-disk" aria-hidden="true"></i>
                                        <span>Зберегти назву</span>
                                    </button>
                                </div>
                            </section>
                        </form>
                        <div class="card pro-leads-gate">
                            <span class="pro-leads-gate__icon"><i class="fa-solid fa-lock" aria-hidden="true"></i></span>
                            <div>
                                <strong>Решта профілю відкривається з PRO</strong>
                                <p>Фото, опис, послуги, контакти, досьє, SEO та публікація редагуються з активною підпискою. Перегляди й аналітику ви вже бачите безкоштовно.</p>
                            </div>
                            <a href="{{ route('pro.account.billing.pay', $currentProfile) }}" class="btn btn--primary account-btn--mini">Оформити підписку</a>
                        </div>
                    </section>
                    @endif
                    <div class="pro-profile-mobile-settings" data-pro-profile-mobile-settings>
                        <details class="pro-profile-mobile-setting" data-pro-mobile-profile-accordion="notifications">
                            <summary>
                                <span class="pro-profile-mobile-setting__icon"><i class="fa-regular fa-bell" aria-hidden="true"></i></span>
                                <span class="pro-profile-mobile-setting__text">
                                    <strong>Сповіщення</strong>
                                    <small>Канали, налаштування та останні події профілю</small>
                                </span>
                                @if (($notificationUnreadCount ?? 0) > 0)
                                    <span class="account-nav__badge pro-profile-mobile-setting__badge" data-pro-notifications-nav-badge>{{ min((int) $notificationUnreadCount, 99) }}</span>
                                @endif
                                <i class="fa-solid fa-chevron-down pro-profile-mobile-setting__chevron" aria-hidden="true"></i>
                            </summary>
                            <div class="pro-profile-mobile-setting__body" data-pro-mobile-profile-slot="notifications"></div>
                        </details>

                        <details class="pro-profile-mobile-setting" data-pro-mobile-profile-accordion="billing">
                            <summary>
                                <span class="pro-profile-mobile-setting__icon"><i class="fa-regular fa-credit-card" aria-hidden="true"></i></span>
                                <span class="pro-profile-mobile-setting__text">
                                    <strong>Оплата</strong>
                                    <small>PRO-тариф, monopay і історія платежів</small>
                                </span>
                                <i class="fa-solid fa-chevron-down pro-profile-mobile-setting__chevron" aria-hidden="true"></i>
                            </summary>
                            <div class="pro-profile-mobile-setting__body" data-pro-mobile-profile-slot="billing"></div>
                        </details>
                    </div>
                    </div>
                @endif

                @if ($currentProfile && $tab === 'reviews')
                    <div class="pro-account-tab-stack" data-pro-tab-panel="reviews" @if ($tab !== 'reviews') hidden @endif>
                    @php
                        $reviewRows = collect($reviews ?? []);
                        $activeReview = $reviewRows->firstWhere('status', 'published') ?? $reviewRows->first();
                    @endphp

                    <section class="pro-reviews-workspace" data-pro-reviews-workspace>
                        <div class="pro-reviews-main">
                            <div class="pro-reviews-heading">
                                <div>
                                    <h2>Відгуки</h2>
                                    <p>Відповідайте на відгуки клієнтів та керуйте репутацією компанії.</p>
                                </div>
                            </div>

                            <div class="pro-reviews-summary">
                                <button type="button" class="pro-reviews-summary-card is-blue" data-review-summary-filter="without_reply">
                                    <span><i class="fa-solid fa-message" aria-hidden="true"></i></span>
                                    <strong>{{ $reviewStatusSummary['without_reply'] ?? 0 }}</strong>
                                    <div>
                                        <b>Без відповіді</b>
                                        <small>нових відгуків</small>
                                    </div>
                                </button>
                                <button type="button" class="pro-reviews-summary-card is-red" data-review-summary-filter="negative">
                                    <span><i class="fa-solid fa-face-frown" aria-hidden="true"></i></span>
                                    <strong>{{ $reviewStatusSummary['negative'] ?? 0 }}</strong>
                                    <div>
                                        <b>Негативні</b>
                                        <small>потребують уваги</small>
                                    </div>
                                </button>
                                <button type="button" class="pro-reviews-summary-card is-muted" data-review-summary-filter="hidden">
                                    <span><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span>
                                    <strong>{{ $reviewStatusSummary['hidden'] ?? 0 }}</strong>
                                    <div>
                                        <b>На перевірці</b>
                                        <small>модераційні кейси</small>
                                    </div>
                                </button>
                            </div>

                            <div class="pro-reviews-toolbar">
                                <label class="pro-reviews-search">
                                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                                    <input type="search" placeholder="Пошук відгуків за ім'ям, текстом або джерелом..." data-review-search>
                                </label>
                                <label class="pro-reviews-sort">
                                    <select data-review-sort aria-label="Сортування відгуків">
                                        <option value="newest">Нові спочатку</option>
                                        <option value="oldest">Старі спочатку</option>
                                        <option value="rating_desc">Найвища оцінка</option>
                                        <option value="rating_asc">Найнижча оцінка</option>
                                    </select>
                                </label>
                            </div>

                            <div class="pro-reviews-filters" role="tablist" aria-label="Фільтри відгуків">
                                <button type="button" class="is-active" data-review-filter="all">Усі <span>{{ $reviewStatusSummary['published'] ?? $reviewRows->where('status', 'published')->count() }}</span></button>
                                <button type="button" data-review-filter="without_reply">Без відповіді <span>{{ $reviewStatusSummary['without_reply'] ?? 0 }}</span></button>
                                <button type="button" data-review-filter="negative">Негативні <span>{{ $reviewStatusSummary['negative'] ?? 0 }}</span></button>
                                <button type="button" data-review-filter="positive">Позитивні <span>{{ $reviewStatusSummary['positive'] ?? 0 }}</span></button>
                                <button type="button" data-review-filter="hidden">На перевірці <span>{{ $reviewStatusSummary['hidden'] ?? 0 }}</span></button>
                            </div>

                            <div class="pro-reviews-list" data-review-list>
                                @forelse ($reviewRows as $review)
                                    @php
                                        $reviewAuthor = $review->external_review_author ?: ($review->author_name ?: 'Користувач DOVIRA');
                                        $reviewAvatarUrl = \App\Support\MediaUrl::avatarUrl(
                                            $review->external_review_author_avatar_url ?: $review->author?->avatar_url,
                                            $reviewAuthor,
                                            96
                                        );
                                        $reviewAvatarInitial = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($reviewAuthor, 0, 1));
                                        $reviewDate = $review->external_review_date ?: ($review->published_at ?: $review->created_at);
                                        $reviewDateLabel = $reviewDate ? $reviewDate->translatedFormat('d F Y') : 'Без дати';
                                        $reviewSourceLabel = match ($review->external_source_type) {
                                            'google' => 'Відгук з Google',
                                            'facebook' => 'Відгук з Facebook',
                                            default => $review->external_source_type ? 'Зовнішній відгук' : 'Відгук на DOVIRA',
                                        };
                                        $reviewFull = (int) floor((float) $review->rating);
                                        $reviewEmpty = max(0, 5 - $reviewFull);
                                        $reviewBody = trim((string) ($review->body ?: $review->title));
                                        $reviewFilterText = mb_strtolower($reviewAuthor . ' ' . $reviewBody . ' ' . $reviewSourceLabel);
                                    @endphp

                                    <article
                                        class="pro-reviews-card {{ $review->status === 'hidden' ? 'is-hidden-review' : '' }} {{ optional($activeReview)->id === $review->id ? 'is-active' : '' }}"
                                        data-review-card
                                        data-review-id="{{ $review->id }}"
                                        data-review-search-text="{{ $reviewFilterText }}"
                                        data-review-date="{{ optional($reviewDate)->timestamp ?? 0 }}"
                                        data-review-rating="{{ (float) $review->rating }}"
                                        data-review-status="{{ $review->status }}"
                                        data-review-has-reply="{{ $review->officialReply ? 'true' : 'false' }}"
                                        data-review-imported="{{ $review->external_source_type ? 'true' : 'false' }}"
                                        data-review-detail-url="{{ route('pro.account.reviews.detail', $review) }}"
                                    >
                                        <button type="button" class="pro-reviews-card__select" data-review-select aria-label="Відкрити відгук {{ $reviewAuthor }}">
                                            <span class="pro-reviews-avatar {{ $reviewAvatarUrl ? '' : 'has-random-gradient' }}" data-review-avatar data-seed="{{ $reviewAuthor }}">
                                                @if ($reviewAvatarUrl)
                                                    <img src="{{ $reviewAvatarUrl }}" alt="{{ $reviewAuthor }}" loading="lazy" data-review-avatar-image>
                                                @endif
                                                <span class="pro-review-avatar-fallback" data-review-avatar-fallback @if ($reviewAvatarUrl) hidden @endif>{{ $reviewAvatarInitial }}</span>
                                            </span>
                                            <span class="pro-reviews-card__body">
                                                <span class="pro-reviews-card__top">
                                                    <span>
                                                        <strong>{{ $reviewAuthor }}</strong>
                                                        <small>{{ $reviewDateLabel }} · {{ $currentProfile->city ?: 'Без локації' }}</small>
                                                    </span>
                                                    <em class="{{ $review->officialReply ? 'is-answered' : 'is-waiting' }}" @if ($review->officialReply) title="Відповідь опублікована" aria-label="Відповідь опублікована" @endif>
                                                        @if ($review->officialReply)
                                                            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                                        @else
                                                            Без відповіді
                                                        @endif
                                                    </em>
                                                </span>
                                                <span class="pro-reviews-card__rating">
                                                    <span class="rating-stars">
                                                        @for ($i = 0; $i < $reviewFull; $i++)
                                                            <i class="fa-solid fa-star" aria-hidden="true"></i>
                                                        @endfor
                                                        @for ($i = 0; $i < $reviewEmpty; $i++)
                                                            <i class="fa-regular fa-star" aria-hidden="true"></i>
                                                        @endfor
                                                    </span>
                                                    <b>{{ number_format((float) $review->rating, 1) }}</b>
                                                </span>
                                                <span class="pro-reviews-card__text">{{ \Illuminate\Support\Str::limit($reviewBody ?: 'Текст відгуку відсутній.', 110) }}</span>
                                                <span class="pro-reviews-card__footer">
                                                    <span class="pro-reviews-source">
                                                        @if ($review->external_source_type === 'google')
                                                            <b aria-hidden="true">G</b>
                                                        @else
                                                            <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                                                        @endif
                                                        {{ $reviewSourceLabel }}
                                                    </span>
                                                    <span class="pro-reviews-card__actions">
                                                        <span><i class="fa-solid fa-reply" aria-hidden="true"></i> Деталі</span>
                                                    </span>
                                                </span>
                                            </span>
                                        </button>
                                    </article>
                                @empty
                                    <p class="account-empty">Відгуків для цього профілю ще немає.</p>
                                @endforelse
                            </div>

                            <button type="button" class="pro-reviews-more" data-review-more hidden>
                                <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                <span>Показати ще</span>
                            </button>

                            <p class="pro-reviews-empty" data-review-empty hidden>За цим фільтром нічого не знайдено.</p>
                        </div>

                        <aside class="pro-reviews-side" data-review-detail-panel>
                            @if ($activeReview)
                                @include('pro.partials.review-detail-panel', [
                                    'review' => $activeReview,
                                    'currentProfile' => $currentProfile,
                                    'canManageReviewModeration' => $canManageReviewModeration,
                                    'isLivewireProAccount' => true,
                                ])
                            @else
                                <div class="pro-reviews-detail pro-reviews-detail--empty">
                                    <h2>Відгук</h2>
                                    <p>Оберіть відгук зі списку, щоб відповісти або змінити його видимість.</p>
                                </div>
                            @endif
                        </aside>
                    </section>
                    </div>
                @endif

                @php
                    // Which scenario/step the inline wizard opens on. After a failed
                    // POST we reopen the exact step with old() values; a preselected
                    // profile (?claim_profile=) jumps straight to the claim details.
                    $claimHasErrors = $errors->hasAny(['profile_id', 'proof_document_url', 'note']);
                    $createHasErrors = $errors->hasAny(['name', 'category_id', 'subcategory_id', 'city', 'website', 'email', 'phone', 'short_description']);
                    $oldClaimProfile = old('profile_id')
                        ? \App\Models\Profile::query()->find((int) old('profile_id'))
                        : $selectedClaimProfile;

                    $claimWizardFlow = match (true) {
                        $createHasErrors || old('name') !== null || request('mode') === 'create' => 'create',
                        $claimHasErrors || $oldClaimProfile || request('mode') === 'claim' => 'claim',
                        default => '',
                    };
                    $claimWizardStep = match ($claimWizardFlow) {
                        'create' => ($errors->hasAny(['website', 'email']) && ! $errors->has('name'))
                            ? 'create-contacts'
                            : 'create-basics',
                        'claim' => $oldClaimProfile ? 'claim-details' : 'claim-search',
                        default => 'scenario',
                    };
                    $claimSupportUsername = $siteSupportTelegramUsername;
                    $claimSupportMessageFor = static function (?\App\Models\Profile $profile) use ($user): string {
                        if (! $profile) {
                            return '';
                        }

                        $profileUrl = filled($profile->slug)
                            ? route('profile.show', ['slug' => $profile->slug])
                            : route('pro.account', ['tab' => 'claims', 'claim_profile' => $profile->id]);

                        return trim(sprintf(
                            'Вітаю! Хочу підтвердити права на профіль "%s" на DOVIRA. ID профілю: %d. Посилання: %s. Мій акаунт: %s.',
                            $profile->name,
                            $profile->id,
                            $profileUrl,
                            $user->email
                        ));
                    };
                    $claimSupportMessage = $claimSupportMessageFor($oldClaimProfile);
                    $claimSupportHref = 'https://t.me/' . $claimSupportUsername
                        . ($claimSupportMessage !== '' ? '?text=' . rawurlencode($claimSupportMessage) : '');
                @endphp

                <div class="pro-account-tab-stack" data-pro-tab-panel="claims" data-claim-wizard data-claim-initial-flow="{{ $claimWizardFlow }}" data-claim-initial-step="{{ $claimWizardStep }}" data-claim-user-email="{{ $user->email }}" data-claim-support-username="{{ $claimSupportUsername }}" @if ($tab !== 'claims' && $currentProfile) hidden @endif>
                    @unless (auth()->user()?->hasVerifiedEmail())
                        <section class="card account-block account-verify-notice">
                            <div class="account-verify-notice__icon"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></div>
                            <div class="account-verify-notice__body">
                                <strong>Підтвердіть email, щоб керувати профілями</strong>
                                <p>Привʼязка чи створення профілю доступні лише з підтвердженою поштою — це захищає бізнес від чужих заявок. Ми надіслали лист на <b>{{ auth()->user()?->email }}</b>.</p>
                                @if (session('status') === 'verification-link-sent')
                                    <p class="account-alert account-alert--success">Новий лист підтвердження надіслано.</p>
                                @elseif (session('status') === 'verification-link-failed')
                                    <p class="account-alert account-alert--error">Не вдалося надіслати лист. Перевірте поштові налаштування або спробуйте пізніше.</p>
                                @endif
                                <form method="POST" action="{{ route('verification.send') }}">
                                    @csrf
                                    <button type="submit" class="btn btn--primary account-btn--mini">
                                        <i class="fa-solid fa-paper-plane" aria-hidden="true"></i><span>Надіслати лист повторно</span>
                                    </button>
                                </form>
                            </div>
                        </section>
                    @endunless
                    @if (auth()->user()?->hasVerifiedEmail())
                    <section class="card account-block pro-profile-entry claim-wizard">
                        <div class="account-block__head claim-wizard__head">
                            <button type="button" class="claim-wizard__back" data-claim-back hidden>
                                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                                <span>Назад</span>
                            </button>
                            <h2 data-claim-title>Керувати профілем</h2>
                            <p data-claim-lead>Якщо профіль уже є на Dovira, клієнти можуть бачити його в каталозі й знаходити в Google. Підтвердьте, що сторінка ваша — це безкоштовно.</p>
                            <div class="claim-wizard__progress" data-claim-progress hidden aria-hidden="true">
                                <span class="claim-wizard__progress-step" data-claim-progress-dot="1"></span>
                                <span class="claim-wizard__progress-step" data-claim-progress-dot="2"></span>
                            </div>
                        </div>

                        {{-- STEP: scenario --}}
                        <div class="claim-wizard__step" data-claim-step="scenario">
                            <div class="pro-profile-entry-grid" aria-label="Вибір способу додавання профілю">
                                <button type="button" class="pro-profile-entry-card" data-claim-flow-pick="claim">
                                    <span class="pro-profile-entry-card__icon">
                                        <i class="fa-solid fa-link" aria-hidden="true"></i>
                                    </span>
                                    <span class="pro-profile-entry-card__content">
                                        <strong>Керувати існуючим</strong>
                                        <small>Знайдіть публічний профіль і підтвердіть права — безкоштовно.</small>
                                    </span>
                                    <span class="pro-profile-entry-card__arrow">
                                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                    </span>
                                </button>

                                <button type="button" class="pro-profile-entry-card" data-claim-flow-pick="create">
                                    <span class="pro-profile-entry-card__icon pro-profile-entry-card__icon--create">
                                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
                                    </span>
                                    <span class="pro-profile-entry-card__content">
                                        <strong>Створити новий профіль</strong>
                                        <small>Додайте компанію або спеціаліста, якщо профілю ще немає на DOVIRA.</small>
                                    </span>
                                    <span class="pro-profile-entry-card__arrow">
                                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                    </span>
                                </button>
                            </div>
                        </div>

                    {{-- FLOW: link an existing profile --}}
                    <form method="POST" action="{{ route('pro.account.claims.store') }}" class="account-form claim-wizard__flow" data-claim-flow="claim" @if ($claimWizardFlow !== 'claim') hidden @endif>
                        @csrf
                        <input type="hidden" name="profile_id" value="{{ old('profile_id', $selectedClaimProfile?->id) }}" data-claim-profile-id>

                        {{-- STEP: search & pick a profile (client-side, no reload) --}}
                        <div class="claim-wizard__step" data-claim-step="claim-search" @if ($claimWizardStep !== 'claim-search') hidden @endif>
                            <p class="claim-wizard__step-lead">Знайдіть профіль, який уже бачать клієнти на Dovira або в Google, і оберіть його зі списку.</p>

                            <div class="pro-account-claim-search" data-claim-search>
                                <div class="pro-account-claim-search__field">
                                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                                    <input type="text" placeholder="Назва компанії, slug, місто або сайт" autocomplete="off" data-claim-search-input>
                                </div>
                                <div class="pro-account-claim-search__suggest" data-claim-suggest hidden></div>
                            </div>

                            <div class="pro-account-claim-selected" data-claim-selected data-profile-slug="{{ $oldClaimProfile?->slug }}" @unless ($oldClaimProfile) hidden @endunless>
                                <div>
                                    <strong data-claim-selected-name>{{ $oldClaimProfile?->name }}</strong>
                                    <span data-claim-selected-meta>{{ $oldClaimProfile?->city ?: '' }}</span>
                                </div>
                                <button type="button" class="pro-account-claim-selected__change" data-claim-clear>Змінити</button>
                            </div>

                            <p class="claim-wizard__error" data-claim-error hidden></p>
                        </div>

                        {{-- STEP: підтвердження прав кодом (OTP на контакт із профілю) --}}
                        <div class="claim-wizard__step" data-claim-step="claim-details" @if ($claimWizardStep !== 'claim-details') hidden @endif>
                            @if ($selectedClaimProfile && $claimAlreadyOwnedByUser)
                                <p class="account-alert account-alert--success">Цей профіль уже прив’язаний до вашого акаунта.</p>
                            @endif

                            <div class="claim-otp" data-claim-otp>
                                <p class="claim-wizard__step-lead">Підтвердимо безкоштовно, що профіль ваш. Оберіть, куди надіслати код: на email або телефон, указаний у профілі. Після підтвердження одразу побачите статистику переглядів.</p>

                                <div class="claim-otp__channels" data-otp-channels></div>

                                <div class="claim-otp__enter" data-otp-enter hidden>
                                    <p class="claim-otp__sent" data-otp-sent></p>
                                    <div class="claim-otp__code-row">
                                        <input type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="Код із 6 цифр" data-otp-code>
                                        <button type="button" class="btn btn--primary" data-otp-verify><span>Підтвердити</span></button>
                                    </div>
                                    <button type="button" class="claim-otp__resend" data-otp-resend>Надіслати код ще раз</button>
                                </div>

                                <p class="claim-wizard__error" data-otp-error hidden></p>
                            </div>

                            <div class="claim-support" data-claim-support data-support-base-url="https://t.me/{{ $claimSupportUsername }}">
                                <div class="claim-support__body">
                                    <span class="claim-support__icon"><i class="fa-brands fa-telegram" aria-hidden="true"></i></span>
                                    <div>
                                        <strong>Підтвердити через підтримку</strong>
                                        <p>Напишіть нам у Telegram — ми перевіримо профіль вручну.</p>
                                        <small data-claim-support-status>Готовий текст звернення скопіюється перед відкриттям Telegram.</small>
                                    </div>
                                </div>
                                <a
                                    class="btn btn--primary claim-support__link"
                                    href="{{ $claimSupportHref }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    data-claim-support-link
                                    data-support-message="{{ $claimSupportMessage }}"
                                >
                                    <i class="fa-brands fa-telegram" aria-hidden="true"></i>
                                    <span>Написати</span>
                                </a>
                            </div>

                            {{-- Fallback: у профілі немає контактів → ручна заявка --}}
                            <div class="claim-manual" data-claim-manual hidden>
                                <p class="account-alert">У профілі немає email чи телефону для миттєвої перевірки. Подайте заявку — ми перевіримо права вручну, після цього можна буде оформити підписку на керування.</p>
                                <label>
                                    <span>Коментар для модератора</span>
                                    <textarea name="note" rows="4" placeholder="Коротко поясніть, чому саме ви маєте керувати цим профілем.">{{ old('note') }}</textarea>
                                </label>
                                <label>
                                    <span>Посилання на підтвердження (необов'язково)</span>
                                    <input type="url" name="proof_document_url" value="{{ old('proof_document_url') }}" placeholder="Сайт, LinkedIn, документ">
                                </label>
                                <button type="submit" class="btn btn--primary claim-manual__submit">Надіслати заявку на перевірку</button>
                            </div>

                            <p class="claim-wizard__error" data-claim-error hidden></p>
                        </div>

                        <footer class="claim-wizard__foot">
                            <button type="button" class="btn btn--primary claim-wizard__next" data-claim-next>
                                <span>Далі</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </button>
                        </footer>
                    </form>

                    {{-- FLOW: create a brand-new profile --}}
                    <form method="POST" action="{{ route('pro.account.profiles.store') }}" class="account-form claim-wizard__flow" data-claim-flow="create" @if ($claimWizardFlow !== 'create') hidden @endif>
                        @csrf

                        {{-- STEP: basics --}}
                        <div class="claim-wizard__step" data-claim-step="create-basics" @if ($claimWizardStep !== 'create-basics') hidden @endif>
                            <p class="claim-wizard__step-lead">Основне про профіль. Решту можна додати згодом у редакторі.</p>
                            <div class="pro-account-form__grid">
                                <label>
                                    <span>Назва профілю</span>
                                    <input type="text" name="name" value="{{ old('name') }}" placeholder="Назва компанії або імʼя спеціаліста" data-claim-name>
                                    @error('name')<small class="claim-wizard__field-error">{{ $message }}</small>@enderror
                                </label>
                                <label>
                                    <span>Місто</span>
                                    <input type="text" name="city" value="{{ old('city') }}" placeholder="Київ, Львів, Одеса...">
                                </label>
                                <label>
                                    <span>Категорія</span>
                                    <select name="category_id" data-category-root-select data-category-child-target="create-profile-subcategory">
                                        <option value="">Оберіть категорію</option>
                                        @foreach ($categories as $category)
                                            <option value="{{ $category->id }}" @selected((int) old('category_id') === (int) $category->id)>{{ $category->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>
                                    <span>Підкатегорія</span>
                                    <select name="subcategory_id" id="create-profile-subcategory" data-selected-value="{{ old('subcategory_id') }}">
                                        <option value="">Спочатку оберіть категорію</option>
                                    </select>
                                </label>
                            </div>
                            <p class="claim-wizard__error" data-claim-error hidden></p>
                        </div>

                        {{-- STEP: contacts --}}
                        <div class="claim-wizard__step" data-claim-step="create-contacts" @if ($claimWizardStep !== 'create-contacts') hidden @endif>
                            <p class="claim-wizard__step-lead">Контакти й опис — необов'язково, але з ними профіль виглядає повнішим.</p>
                            <div class="pro-account-form__grid">
                                <label>
                                    <span>Сайт</span>
                                    <input type="url" name="website" value="{{ old('website') }}" placeholder="https://example.com">
                                    @error('website')<small class="claim-wizard__field-error">{{ $message }}</small>@enderror
                                </label>
                                <label>
                                    <span>Email</span>
                                    <input type="email" name="email" value="{{ old('email') }}" placeholder="contact@example.com">
                                    @error('email')<small class="claim-wizard__field-error">{{ $message }}</small>@enderror
                                </label>
                                <label>
                                    <span>Телефон</span>
                                    <input type="text" name="phone" value="{{ old('phone') }}" placeholder="+380..." data-phone-mask inputmode="tel" autocomplete="tel">
                                </label>
                                <label>
                                    <span>Короткий опис</span>
                                    <input type="text" name="short_description" value="{{ old('short_description') }}" placeholder="Чим займається профіль і для кого">
                                </label>
                            </div>
                        </div>

                        <footer class="claim-wizard__foot">
                            <button type="button" class="btn btn--primary claim-wizard__next" data-claim-next>
                                <span>Далі</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </button>
                            <button type="submit" class="btn btn--primary claim-wizard__submit" data-claim-submit hidden>
                                Створити профіль
                            </button>
                        </footer>
                    </form>
                    </section>
                    @endif

                    <section class="card account-block">
                        <div class="account-block__head">
                            <h2>Мої заявки</h2>
                            <p>Усі статуси прив’язки в одному місці.</p>
                        </div>

                        <div class="pro-account-list">
                            @forelse ($userClaims as $claim)
                                <article class="pro-account-list__item pro-account-list__item--row">
                                    <div>
                                        <strong>{{ $claim->profile?->name ?? 'Профіль недоступний' }}</strong>
                                        <p>{{ $claim->company_role ?: 'Роль не вказана' }}</p>
                                    </div>
                                    <span class="account-status {{ match ($claim->status) {
                                        'approved' => 'is-published',
                                        'pending', 'need_more_info' => 'is-pending',
                                        'rejected' => 'is-rejected',
                                        default => 'is-muted',
                                    } }}">{{ match ($claim->status) {
                                        'approved' => 'Підтверджено',
                                        'pending' => 'На розгляді',
                                        'need_more_info' => 'Потрібні дані',
                                        'rejected' => 'Відхилено',
                                        default => $claim->status,
                                    } }}</span>
                                </article>
                            @empty
                                <p class="account-empty">Ще немає заявок на прив’язку.</p>
                            @endforelse
                        </div>
                    </section>
                </div>
            </div>
        </div>
        <script id="pro-account-category-children" type="application/json">@json($categoryChildren)</script>
        <script id="pro-account-service-candidates" type="application/json">@json($categoryServices->map(fn ($service) => ['category_id' => (int) $service->category_id, 'name' => $service->name])->values())</script>
        <script id="pro-account-region-city-directory" type="application/json">@json($regionCityDirectory)</script>

        {{-- Разовий попап-підказка про віджети (бейдж + блок відгуків).
             Показ керує pro.js: один раз на браузер (localStorage), CTA веде
             до картки віджетів у вкладці «Огляд». --}}
        @if ($currentProfile && $currentProfile->is_published)
            <div class="pro-widget-promo" data-widget-promo hidden>
                <div class="pro-widget-promo__backdrop" data-widget-promo-close></div>
                <div class="pro-widget-promo__card" role="dialog" aria-modal="true" aria-labelledby="pro-widget-promo-title">
                    <button type="button" class="pro-widget-promo__x" data-widget-promo-close aria-label="Закрити">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                    <div class="pro-widget-promo__preview">
                        <img src="{{ route('widget.badge', ['slug' => $currentProfile->slug]) }}" alt="Бейдж рейтингу {{ $currentProfile->name }}" width="240" height="72" loading="lazy">
                    </div>
                    <h3 id="pro-widget-promo-title">Покажіть свій рейтинг на власному сайті</h3>
                    <ul class="pro-widget-promo__list">
                        <li>
                            <b>Більше довіри — більше заявок.</b>
                            Живий рейтинг і справжні відгуки клієнтів прямо на вашому сайті чи в соцмережах.
                        </li>
                        <li>
                            <b>Вище в Google.</b>
                            Бейдж і блок відгуків посилаються на ваш профіль — це підсилює його позиції в пошуку.
                        </li>
                        <li>
                            <b>Бонус −20%.</b>
                            За розміщений бейдж діє знижка 20% на продовження підписки.
                        </li>
                    </ul>
                    <div class="pro-widget-promo__actions">
                        <button type="button" class="btn btn--primary" data-widget-promo-go>Показати віджети</button>
                        <button type="button" class="pro-widget-promo__later" data-widget-promo-close>Пізніше</button>
                    </div>
                </div>
            </div>
            <style>
                .pro-widget-promo { position: fixed; inset: 0; z-index: 120; display: flex; align-items: center; justify-content: center; padding: 20px; }
                .pro-widget-promo[hidden] { display: none; }
                .pro-widget-promo__backdrop { position: absolute; inset: 0; background: rgba(15, 23, 42, .45); backdrop-filter: blur(2px); }
                .pro-widget-promo__card { position: relative; width: 100%; max-width: 430px; padding: 26px 26px 22px; background: #fff; border-radius: 20px; box-shadow: 0 30px 80px rgba(15, 23, 42, .3); animation: pro-widget-promo-in .25s ease; }
                @keyframes pro-widget-promo-in { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
                .pro-widget-promo__x { position: absolute; top: 14px; right: 14px; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border: 0; border-radius: 50%; background: #f1f4f9; color: #64748b; cursor: pointer; }
                .pro-widget-promo__x:hover { background: #e5eaf2; color: #1e293b; }
                .pro-widget-promo__x svg { width: 15px; height: 15px; }
                .pro-widget-promo__preview { display: flex; justify-content: center; margin: 2px 0 16px; }
                .pro-widget-promo__preview img { display: block; border-radius: 14px; box-shadow: 0 12px 30px rgba(23, 42, 90, .16); }
                .pro-widget-promo__card h3 { margin: 0 0 12px; color: var(--c-heading, #1e293b); font-size: 19px; font-weight: 800; letter-spacing: -.01em; text-align: center; }
                .pro-widget-promo__list { display: grid; gap: 10px; margin: 0 0 18px; padding: 0; list-style: none; color: var(--c-muted, #64748b); font-size: 13.5px; line-height: 1.5; }
                .pro-widget-promo__list b { color: var(--c-heading, #1e293b); }
                .pro-widget-promo__list li { position: relative; padding-left: 22px; }
                .pro-widget-promo__list li::before { content: "✓"; position: absolute; left: 0; top: 0; color: #16a34a; font-weight: 800; }
                .pro-widget-promo__actions { display: grid; gap: 8px; }
                .pro-widget-promo__actions .btn { justify-content: center; width: 100%; }
                .pro-widget-promo__later { padding: 8px; border: 0; background: transparent; color: var(--c-muted, #64748b); font: inherit; font-size: 13.5px; font-weight: 650; cursor: pointer; }
                .pro-widget-promo__later:hover { color: var(--c-heading, #1e293b); }
            </style>
        @endif
    </section>
