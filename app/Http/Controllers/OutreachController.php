<?php

namespace App\Http\Controllers;

use App\Models\EmailLog;
use App\Models\PaymentOrder;
use App\Models\Profile;
use App\Models\ProfileClaim;
use App\Models\ProfileEvent;
use App\Services\ProfileAnalyticsService;
use App\Support\CategoryHierarchy;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Окрема (поза адмінкою) сторінка розсилки адвокатам: список усіх адвокатів,
 * шаблон повідомлення з автопідстановкою імені та посилання на профіль, а
 * збоку — всі контакти й соцмережі вибраного. Дані віддаються один раз, уся
 * інтерактивність — на клієнті.
 *
 * Доступ — лише персоналу (адмінка/сайт), бо сторінка відкриває контакти всіх
 * адвокатів масово.
 */
class OutreachController extends Controller
{
    /** Категорія-джерело для «адвокатів» — та сама, що на головній. */
    private const LAWYER_CATEGORY_NAME = 'Адвокати';

    /** Ролі, яким дозволено відкривати сторінку. */
    private const ALLOWED_ROLES = ['admin', 'moderator', 'editor', 'support'];

    /** Мережа → людська назва. */
    private const SOCIAL_LABELS = [
        'instagram' => 'Instagram',
        'telegram' => 'Telegram',
        'facebook' => 'Facebook',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
        'linkedin' => 'LinkedIn',
        'x' => 'X',
        'twitter' => 'X',
        'viber' => 'Viber',
        'whatsapp' => 'WhatsApp',
    ];

    /** Людські назви подій-етапів воронки. */
    private const EVENT_LABELS = [
        'profile_view' => 'Перегляд профілю',
        'info_tab_view' => 'Вкладка «Інформація»',
        'dossier_open' => 'Відкрив досьє',
        'dossier_view' => 'Переглянув досьє',
        'website_click' => 'Сайт',
        'phone_click' => 'Телефон',
        'email_click' => 'Email',
        'telegram_click' => 'Telegram',
        'viber_click' => 'Viber',
        'whatsapp_click' => 'WhatsApp',
        'instagram_click' => 'Instagram',
        'facebook_click' => 'Facebook',
        'map_click' => 'Карта',
    ];

    /** Емодзі для варіантів листа (utm_content = stepId_variant). */
    public const VARIANT_MARKS = ['red' => '🔴', 'yellow' => '🟡', 'green' => '🟢', 'neutral' => '⚪'];

    public function index(): View|RedirectResponse
    {
        if ($redirect = $this->guardStaff()) {
            return $redirect;
        }

        return view('outreach.index', [
            'lawyers' => $this->lawyers(),
            'sequences' => array_values(config('outreach.sequences', [])),
            'utm' => [
                'source' => (string) config('outreach.utm.source', 'outreach'),
                'medium' => (string) config('outreach.utm.medium', 'dm'),
                'campaign' => (string) config('outreach.campaign', 'lawyers'),
            ],
            'pendingCount' => $this->pendingClaimsCount(),
        ]);
    }

    /**
     * Легкий refresh статусів для сторінки /rozsylka. Плашки «перейшов»,
     * «акаунт», «оплатив» і «немає Telegram» мають братися з БД, а не з
     * одноразового HTML-рендера чи локального стану браузера.
     */
    public function status(): JsonResponse|RedirectResponse
    {
        if ($redirect = $this->guardStaff()) {
            return $redirect;
        }

        $categoryIds = CategoryHierarchy::categoryIdsFromNames([self::LAWYER_CATEGORY_NAME]);

        if (empty($categoryIds)) {
            return response()->json(['profiles' => []]);
        }

        $columns = ['id', 'owner_user_id', 'is_owner_verified', 'is_pro'];
        if (Schema::hasColumn('profiles', 'outreach_flags')) {
            $columns[] = 'outreach_flags';
        }

        $profiles = Profile::query()
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds))
            ->get($columns);

        $status = $this->profileStatuses($profiles->pluck('id')->map(fn ($id) => (int) $id)->all());

        return response()->json([
            'profiles' => $profiles
                ->map(fn (Profile $profile): array => [
                    'id' => (int) $profile->id,
                    'clicked' => $status['clicked']->contains((int) $profile->id),
                    'account' => $this->accountStage($profile, $status['claimed']),
                    'paid' => (bool) $profile->is_pro || $status['paid']->contains((int) $profile->id),
                    'no_telegram' => (bool) data_get($profile->outreach_flags, 'no_telegram'),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Стрічка сповіщень для персоналу: заявки на підтвердження прав і оплати
     * PRO — те, що інакше падає лише в Telegram. Найновіше зверху; заявки,
     * які ще не розглянули, підсвічені як «потребує дії».
     */
    public function notifications(): View|RedirectResponse
    {
        if ($redirect = $this->guardStaff()) {
            return $redirect;
        }

        $publicBase = (string) config('outreach.public_url', 'https://mydovira.com');
        $profileUrl = fn (?Profile $p): ?string => $p && $p->slug
            ? $publicBase . route('profile.show', ['slug' => $p->slug], false)
            : null;

        $claims = ProfileClaim::query()
            ->with(['user:id,name,email', 'profile:id,name,slug,city,is_owner_verified,is_pro'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $payments = PaymentOrder::query()
            ->where('product_type', PaymentOrder::PRODUCT_PRO_SUBSCRIPTION)
            ->with(['user:id,name,email', 'profile:id,name,slug,city'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $feed = collect();

        foreach ($claims as $claim) {
            $feed->push([
                'kind' => 'claim',
                'ts' => $claim->created_at,
                'at' => $claim->created_at?->diffForHumans(),
                'needs_action' => $claim->status === 'pending',
                'status' => (string) $claim->status,
                'title' => 'Заявка на підтвердження прав',
                'user_name' => (string) ($claim->user?->name ?: '—'),
                'user_email' => (string) ($claim->user?->email ?: ''),
                'profile_name' => (string) ($claim->profile?->name ?: ('#' . $claim->profile_id)),
                'profile_url' => $profileUrl($claim->profile),
                'city' => $claim->profile?->city,
                'role' => $claim->company_role ? (string) $claim->company_role : null,
                'note' => $claim->note ? (string) $claim->note : null,
                'amount' => null,
            ]);
        }

        foreach ($payments as $order) {
            $paid = $order->status === PaymentOrder::STATUS_PAID;
            $feed->push([
                'kind' => 'payment',
                'ts' => $order->paid_at ?: $order->created_at,
                'at' => ($order->paid_at ?: $order->created_at)?->diffForHumans(),
                'needs_action' => $order->status === PaymentOrder::STATUS_PENDING,
                'status' => (string) $order->status,
                'title' => $paid ? 'Оплата PRO' : ($order->status === PaymentOrder::STATUS_PENDING ? 'Платіж розпочато' : 'Платіж: ' . $order->status),
                'user_name' => (string) ($order->user?->name ?: '—'),
                'user_email' => (string) ($order->user?->email ?: ''),
                'profile_name' => (string) ($order->profile?->name ?: ('#' . $order->profile_id)),
                'profile_url' => $profileUrl($order->profile),
                'city' => $order->profile?->city,
                'role' => null,
                'note' => null,
                'amount' => number_format((int) round(((int) $order->amount) / 100)) . ' ' . $order->currency,
            ]);
        }

        return view('outreach.notifications', [
            'feed' => $feed->sortByDesc('ts')->values()->all(),
            'pendingClaims' => $claims->where('status', 'pending')->count(),
            'pendingPayments' => $payments->where('status', PaymentOrder::STATUS_PENDING)->count(),
            'paidCount' => $payments->where('status', PaymentOrder::STATUS_PAID)->count(),
        ]);
    }

    /**
     * Позиція профілю в популярних по місту — для повідомлень із живими
     * цифрами. Рахується ліниво (один вибраний профіль), бо для всього списку
     * це був би запит на кожен рядок: ранг береться з того самого порядку, що
     * й у каталозі.
     */
    public function profileRank(Profile $profile): JsonResponse
    {
        if ($redirect = $this->guardStaff()) {
            return response()->json(['ok' => false], 403);
        }

        $context = \Illuminate\Support\Facades\Cache::remember(
            'outreach:rank:v1:' . $profile->id,
            now()->addHours(6),
            fn (): array => app(\App\Services\ProfileAnalyticsService::class)->popularityRankInLocalContext($profile)
        );

        $rank = $context['rank'] ?? null;
        $label = null;

        if ($rank !== null && filled($context['city'] ?? null)) {
            // «2 місце в категорії «Адвокати» у Києві» — фраза одразу готова до
            // вставки в повідомлення. Локатив міста беремо з довідника, бо
            // «у місті Київ» у листі виглядає як машинний текст.
            $category = trim((string) ($context['context_name'] ?? ''));
            $cityPhrase = \App\Support\RegionCityDirectory::cityLocative((string) $context['city'])
                ?? ('у місті ' . $context['city']);

            // Формат підігнаний під речення «…та позиція {позиція}», тож без
            // слова «місце»: інакше виходить «позиція 2 місце».
            $label = '#' . $rank
                . ($category !== '' ? ' у категорії «' . $category . '»' : '')
                . ' ' . $cityPhrase
                . (($context['total'] ?? 0) > 0 ? ' (із ' . (int) $context['total'] . ')' : '');
        }

        return response()->json([
            'ok' => true,
            'rank' => $rank,
            'total' => (int) ($context['total'] ?? 0),
            'label' => $label,
        ]);
    }

    public function updateProfileFlags(Request $request, Profile $profile): JsonResponse|RedirectResponse
    {
        if ($redirect = $this->guardStaff()) {
            return $redirect;
        }

        $validated = $request->validate([
            'no_telegram' => ['required', 'boolean'],
        ]);

        if (! Schema::hasColumn('profiles', 'outreach_flags')) {
            return response()->json([
                'ok' => false,
                'message' => 'Outreach flags storage is not migrated yet.',
            ], 503);
        }

        $flags = (array) ($profile->outreach_flags ?? []);

        if ((bool) $validated['no_telegram']) {
            $flags['no_telegram'] = true;
            $flags['no_telegram_marked_at'] = now()->toIso8601String();
            $flags['no_telegram_marked_by'] = Auth::id();
        } else {
            unset($flags['no_telegram'], $flags['no_telegram_marked_at'], $flags['no_telegram_marked_by']);
        }

        $profile->forceFill([
            'outreach_flags' => $flags === [] ? null : $flags,
        ])->save();

        return response()->json([
            'ok' => true,
            'flags' => $profile->outreach_flags ?? [],
        ]);
    }

    /**
     * Ручне збереження контактів профілю прямо зі сторінки розсилки: оператор
     * вписує email або Telegram (або чистить їх), дані лягають у profiles.email
     * та profiles.social_links.
     */
    public function updateProfileContact(Request $request, Profile $profile): JsonResponse|RedirectResponse
    {
        if ($redirect = $this->guardStaff()) {
            return $redirect;
        }

        $validated = $request->validate([
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'telegram' => ['nullable', 'string', 'max:255'],
        ]);

        $email = trim((string) ($validated['email'] ?? ''));
        $telegramInput = trim((string) ($validated['telegram'] ?? ''));
        $telegramUrl = $telegramInput === '' ? null : $this->normalizeTelegramUrl($telegramInput);

        if ($telegramInput !== '' && $telegramUrl === null) {
            throw ValidationException::withMessages([
                'telegram' => 'Вкажіть Telegram нік або посилання t.me.',
            ]);
        }

        $socialLinks = $profile->social_links;
        if ($request->has('telegram')) {
            $socialLinks = $this->withTelegramSocialLink($socialLinks, $telegramUrl);
        }

        $updates = [];

        if ($request->has('email')) {
            $updates['email'] = $email === '' ? null : $email;
        }

        if ($request->has('telegram')) {
            $updates['social_links'] = $socialLinks;
        }

        if ($telegramUrl && Schema::hasColumn('profiles', 'outreach_flags')) {
            $flags = (array) ($profile->outreach_flags ?? []);
            unset($flags['no_telegram'], $flags['no_telegram_marked_at'], $flags['no_telegram_marked_by']);
            $updates['outreach_flags'] = $flags === [] ? null : $flags;
        }

        $profile->forceFill($updates)->save();
        $profile->refresh();

        return response()->json([
            'ok' => true,
            'email' => $profile->email,
            'telegram' => $this->telegramUrl($profile->social_links),
            'socials' => $this->mapSocials($profile->social_links),
            'flags' => $profile->outreach_flags ?? [],
        ]);
    }

    /**
     * Особистий трекер задач із розкрутки Dovira (лише персонал). Список задач
     * задається на бекенді, вся інтерактивність і прогрес — на клієнті
     * (localStorage), без нових таблиць.
     */
    public function tasks(): View|RedirectResponse
    {
        if ($redirect = $this->guardStaff()) {
            return $redirect;
        }

        return view('outreach.tasks', [
            'groups' => $this->growthTaskGroups(),
        ]);
    }

    /**
     * Дефолтні задачі-підказки. id стабільні — за ними localStorage тримає
     * стан «зроблено», тож текст можна правити без втрати прогресу.
     *
     * @return array<int, array{key:string, title:string, hint?:string, tasks:array<int, array{id:string, text:string, hint?:string, link?:array{href:string, label:string}}>}>
     */
    private function growthTaskGroups(): array
    {
        return [
            [
                'key' => 'audit',
                'title' => '🛡️ Аудит довіри (фідбек перед запуском)',
                'hint' => 'Суперечності «продукт vs обіцянка», які треба закрити до платного запуску.',
                'tasks' => [
                    ['id' => 'audit-review-appeals', 'text' => 'Тримати /pro без обіцянок керування видимістю відгуків', 'hint' => 'Публічний текст має всюди звучати однаково: PRO дає офіційні відповіді та скаргу на модерацію, але не дає приховувати чи видаляти негатив за оплату. Оскарження описане окремим процесом із правилами та строками.', 'link' => ['href' => route('pro'), 'label' => '/pro']],
                    ['id' => 'audit-fake-numbers', 'text' => 'Не показувати в топах профілі зі 100% імпортованими відгуками (або розділити числа)', 'hint' => 'У топах видно патерн ліміту імпорту: 9 з 10 косметологів мають рівно 90 відгуків і 5.0; стеля рівно 1000 / 999. Виглядає згенеровано. Мінімум — ховати профілі без власних відгуків або показувати два числа: «на Dovira: 3 · з Google: 87».'],
                    ['id' => 'audit-rating-discriminates', 'text' => 'Привести рейтинг у відповідність до опису — або алгоритм', 'hint' => 'Майже весь топ = 5.0, директорія не допомагає обрати. При цьому в «Адвокатах» №1 — 4.9/377 вище за 5.0/524. Опис каже «поправка на кількість, без платних множників» — перевірити, чи не впливає бейдж «Dovira рекомендує» і чи він не повʼязаний з оплатою.'],
                    ['id' => 'audit-dirty-catalog', 'text' => 'Прогнати валідацію каталогу: категорія↔slug, одна основна категорія, місто з реєстру', 'hint' => 'Кейси: «Адвокати Запоріжжя» стоїть у нотаріусах Кропивницького; «ОʼДоктор» одночасно №1 у косметології, приватних клініках і стоматології. Для продукту «ми перевіряємо» кожна така помилка коштує дорожче.', 'link' => ['href' => route('catalog'), 'label' => 'Каталог']],
                    ['id' => 'audit-claim-analytics', 'text' => 'Показувати аналітику переглядів одразу після безкоштовного claim', 'hint' => 'Зараз людина проходить верифікацію й потрапляє в порожній кабінет (усе за PRO). Це найгірший варіант — витратили її час і не показали жодної цифри. Саме аналітика переглядів і продає PRO.'],
                    ['id' => 'audit-pricing-clarity', 'text' => 'Прибрати суперечність «разовий платіж за 6 міс» vs «скасувати будь-коли»; ціни у гривні', 'hint' => 'Людина не розуміє, що купує і що буде з грошима при скасуванні. Ціна в доларах для СПД-адвоката з Вінниці — зайвий барʼєр і питання «як я це проведу».'],
                    ['id' => 'audit-deadline-cohort', 'text' => 'Замінити дедлайн на когорту «стартова ціна для перших 500 підтверджених профілів» + лічильник', 'hint' => 'Продовжити дату = дедлайн декоративний; підняти ціну на порожній воронці = вбити пайплайн. Робоче: когорта з лічильником — дефіцит лишається, дата зникає без брехні, і в цю пастку більше не потрапляєш.'],
                    ['id' => 'audit-verticals', 'text' => 'Обрати перші вертикалі pay-per-lead: медицина + будівництво; фінпослуги — наповнити або сховати', 'hint' => 'Медицина — 206 з 473 тис. відгуків; медицина + будівництво > 60%: там уже є і трафік, і інвентар. Фінпослуги (146 компаній) зараз лише розмивають каталог.'],
                ],
            ],
            [
                'key' => 'circle',
                'title' => 'Знайомі та найближче коло',
                'hint' => 'Найтепліший канал — почни звідси.',
                'tasks' => [
                    ['id' => 'circle-list', 'text' => 'Скласти список 20 знайомих із бізнесом'],
                    ['id' => 'circle-dm', 'text' => 'Розіслати їм персональне повідомлення + посилання на їхній профіль у каталозі', 'link' => ['href' => route('catalog'), 'label' => 'Каталог']],
                    ['id' => 'circle-badge', 'text' => 'Надіслати знайомим плашку Dovira (віджет рейтингу для сайту)'],
                    ['id' => 'circle-first-reviews', 'text' => 'Попросити 3 перших залишити чесний відгук'],
                    ['id' => 'circle-claim', 'text' => 'Показати, як безкоштовно підтвердити права на профіль', 'link' => ['href' => route('pro'), 'label' => 'Для бізнесу']],
                ],
            ],
            [
                'key' => 'social',
                'title' => 'Соцмережі',
                'hint' => 'Показати платформу власній аудиторії.',
                'tasks' => [
                    ['id' => 'social-announce', 'text' => 'Пост-анонс Dovira в особистому Instagram / Facebook'],
                    ['id' => 'social-story', 'text' => 'Сторіс із плашкою рейтингу й посиланням на профіль'],
                    ['id' => 'social-groups', 'text' => 'Допис у профільних чатах і групах підприємців'],
                    ['id' => 'social-widget-proof', 'text' => 'Поділитися віджетом-рейтингом як соціальним доказом'],
                ],
            ],
            [
                'key' => 'business',
                'title' => 'Перші бізнеси (пряме залучення)',
                'hint' => 'Ціль — перші живі PRO-профілі.',
                'tasks' => [
                    ['id' => 'biz-shortlist', 'text' => 'Відібрати 10 бізнесів із хорошою репутацією'],
                    ['id' => 'biz-outreach', 'text' => 'Написати їм через інструмент розсилки (готові шаблони)', 'link' => ['href' => route('outreach'), 'label' => 'Розсилка']],
                    ['id' => 'biz-demo', 'text' => 'Запропонувати безкоштовне підтвердження прав + демо PRO'],
                    ['id' => 'biz-funnel', 'text' => 'Перевірити воронку розсилки — де відвалюються', 'link' => ['href' => route('outreach.stats'), 'label' => 'Воронка']],
                ],
            ],
            [
                'key' => 'content',
                'title' => 'Контент і видимість',
                'hint' => 'Довгограючий канал — SEO та AI-пошук.',
                'tasks' => [
                    ['id' => 'content-post', 'text' => 'Опублікувати нову статтю в блог', 'link' => ['href' => route('blog'), 'label' => 'Блог']],
                    ['id' => 'content-index', 'text' => 'Перевірити, що профілі індексуються (sitemap)', 'link' => ['href' => route('seo.sitemap'), 'label' => 'sitemap.xml']],
                    ['id' => 'content-llms', 'text' => 'Перевірити llms.txt для AI-пошуку', 'link' => ['href' => route('seo.llms'), 'label' => 'llms.txt']],
                ],
            ],
            [
                'key' => 'weekly',
                'title' => 'Щотижневі (повторювані)',
                'hint' => 'Скидай прапорці на початку тижня.',
                'tasks' => [
                    ['id' => 'weekly-reviews', 'text' => 'Відповісти на нові відгуки та звернення'],
                    ['id' => 'weekly-outreach', 'text' => '5 нових персональних розсилок', 'link' => ['href' => route('outreach'), 'label' => 'Розсилка']],
                    ['id' => 'weekly-funnel', 'text' => 'Переглянути воронку розсилки за тиждень', 'link' => ['href' => route('outreach.stats'), 'label' => 'Воронка']],
                ],
            ],
        ];
    }

    /**
     * Дашборд воронки розсилки. Все читається з наявних profile_events
     * (utm_source=outreach) — жодних нових таблиць. Етапи: перегляд профілю
     * → клік по контакту; «де вийшов» = найдальший етап адвоката.
     */
    public function stats(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guardStaff()) {
            return $redirect;
        }

        $engageEvents = array_values(array_unique(array_merge(
            ProfileAnalyticsService::WEBSITE_CLICK_EVENTS,
            ProfileAnalyticsService::CONTACT_CLICK_EVENTS,
        )));

        $utmSource = (string) config('outreach.utm.source', 'outreach');

        // Доступні кампанії (для фільтра) + поточний вибір.
        $campaigns = ProfileEvent::query()
            ->where('utm_source', $utmSource)
            ->whereNotNull('utm_campaign')
            ->where('utm_campaign', '!=', '')
            ->distinct()
            ->orderByDesc('utm_campaign')
            ->pluck('utm_campaign')
            ->all();

        $campaign = trim((string) $request->query('campaign', ''));
        if ($campaign !== '' && ! in_array($campaign, $campaigns, true)) {
            $campaign = '';
        }

        $base = fn () => ProfileEvent::query()
            ->where('utm_source', $utmSource)
            ->when($campaign !== '', fn ($q) => $q->where('utm_campaign', $campaign));

        // Кількість різних адвокатів (profile_id), у яких є подія з набору.
        $lawyersWith = fn (array $types) => (int) $base()->whereIn('event_type', $types)->distinct()->count('profile_id');

        // ── Загальна воронка ──
        $viewsTotal = (int) $base()->where('event_type', ProfileAnalyticsService::EVENT_PROFILE_VIEW)->count();
        $lawyersOpened = $lawyersWith([ProfileAnalyticsService::EVENT_PROFILE_VIEW]);
        $clicksTotal = (int) $base()->whereIn('event_type', $engageEvents)->count();
        $lawyersEngaged = $lawyersWith($engageEvents);

        // ── Воронка досьє (що робили з розсилки: перейшли → «Інформація» →
        //    відкрили досьє → переглянули досьє → клікнули контакт) ──
        $funnel = [
            ['key' => 'viewed',       'label' => 'Перейшли на профіль',      'count' => $lawyersOpened],
            ['key' => 'info',         'label' => 'Відкрили «Інформація»',    'count' => $lawyersWith(['info_tab_view'])],
            ['key' => 'dossier_open', 'label' => 'Натиснули «Досьє»',        'count' => $lawyersWith(['dossier_open'])],
            ['key' => 'dossier_view', 'label' => 'Переглянули досьє',        'count' => $lawyersWith(['dossier_view'])],
            ['key' => 'contacted',    'label' => 'Клікнули контакт',         'count' => $lawyersEngaged],
        ];
        $funnelTop = max(1, $lawyersOpened);
        foreach ($funnel as &$stage) {
            $stage['pct'] = (int) round($stage['count'] / $funnelTop * 100);
        }
        unset($stage);

        // ── По каналах (utm_medium) ──
        // dm = ручна розсилка в Telegram/месенджери зі сторінки /rozsylka,
        // email = email-кампанія, sms = SMS. Рахуємо ПЕРЕХОДІВ УСЬОГО (ловить і
        // переслані посилання), УНІКАЛЬНИХ ЛЮДЕЙ (distinct visitor_id — різні
        // люди, зокрема ті, кому адвокат переслав лінк) і АДВОКАТІВ (профілів).
        $channelLabels = ['dm' => 'Telegram (вручну)', 'email' => 'Email', 'sms' => 'SMS'];
        $chViews = $base()->where('event_type', ProfileAnalyticsService::EVENT_PROFILE_VIEW)
            ->selectRaw('utm_medium, count(*) as views, count(distinct visitor_id) as people, count(distinct profile_id) as lawyers')
            ->groupBy('utm_medium')->get()->keyBy('utm_medium');
        $chEng = $base()->whereIn('event_type', $engageEvents)
            ->selectRaw('utm_medium, count(*) as clicks, count(distinct profile_id) as lawyers')
            ->groupBy('utm_medium')->get()->keyBy('utm_medium');

        $byChannel = [];
        foreach ($channelLabels as $medium => $label) {
            $views = (int) ($chViews[$medium]->views ?? 0);
            $people = (int) ($chViews[$medium]->people ?? 0);
            $lawyers = (int) ($chViews[$medium]->lawyers ?? 0);
            $byChannel[] = [
                'medium' => $medium,
                'label' => $label,
                'views' => $views,       // усі переходи, зокрема повторні й переслані
                'people' => $people,     // унікальні відвідувачі (різні люди)
                'lawyers' => $lawyers,   // скільки різних профілів-адвокатів відкрито
                'engaged' => (int) ($chEng[$medium]->lawyers ?? 0),
                'clicks' => (int) ($chEng[$medium]->clicks ?? 0),
                // «Понад адвокатів» ≈ переходи від пересилань/повторів.
                'extra' => max(0, $people - $lawyers),
            ];
        }

        // ── По повідомленнях ──
        $opensByMsg = $base()->where('event_type', ProfileAnalyticsService::EVENT_PROFILE_VIEW)
            ->selectRaw('utm_content, count(*) as views, count(distinct profile_id) as lawyers')
            ->groupBy('utm_content')->get()->keyBy('utm_content');
        $engByMsg = $base()->whereIn('event_type', $engageEvents)
            ->selectRaw('utm_content, count(*) as clicks, count(distinct profile_id) as lawyers')
            ->groupBy('utm_content')->get()->keyBy('utm_content');

        // Розгортаємо всі кроки всіх послідовностей у плоский список: кожен крок —
        // окремий utm_content, тож порівнюємо ефективність не лише сценаріїв, а й
        // конкретних дотиків усередині них.
        $perMessage = [];
        foreach (config('outreach.sequences', []) as $sequence) {
            foreach ((array) ($sequence['steps'] ?? []) as $step) {
                $id = (string) $step['id'];
                $opened = (int) ($opensByMsg[$id]->lawyers ?? 0);
                $engaged = (int) ($engByMsg[$id]->lawyers ?? 0);
                $perMessage[] = [
                    'id' => $id,
                    'label' => (string) ($sequence['label'] ?? '') . ' · ' . (string) $step['label'],
                    'opened' => $opened,
                    'views' => (int) ($opensByMsg[$id]->views ?? 0),
                    'engaged' => $engaged,
                    'clicks' => (int) ($engByMsg[$id]->clicks ?? 0),
                    'rate' => $opened > 0 ? round($engaged / $opened * 100) : 0,
                ];
            }
        }

        // ── Що робили (розбивка по типах кліків) ──
        $byEvent = [];
        $eventRows = $base()->whereIn('event_type', $engageEvents)
            ->selectRaw('event_type, count(*) as c')
            ->groupBy('event_type')->orderByDesc('c')->get();
        foreach ($eventRows as $row) {
            $byEvent[] = [
                'label' => self::EVENT_LABELS[$row->event_type] ?? $row->event_type,
                'count' => (int) $row->c,
            ];
        }

        // ── По адвокатах (хто клікнув, що робив, де вийшов) ──
        $perLawyerRows = $base()
            ->selectRaw(
                "profile_id,
                 sum(case when event_type = ? then 1 else 0 end) as views,
                 sum(case when event_type <> ? then 1 else 0 end) as clicks,
                 max(created_at) as last_at,
                 group_concat(distinct utm_content) as messages,
                 group_concat(distinct event_type) as events",
                [ProfileAnalyticsService::EVENT_PROFILE_VIEW, ProfileAnalyticsService::EVENT_PROFILE_VIEW]
            )
            ->groupBy('profile_id')
            ->orderByRaw('max(created_at) desc')
            ->limit(300)
            ->get();

        $profiles = Profile::query()
            ->whereIn('id', $perLawyerRows->pluck('profile_id'))
            ->get(['id', 'name', 'slug', 'city', 'owner_user_id', 'is_owner_verified', 'is_pro'])
            ->keyBy('id');

        $status = $this->profileStatuses($profiles->keys()->map(fn ($v) => (int) $v)->all());

        $perLawyer = $perLawyerRows->map(function ($row) use ($profiles, $engageEvents, $status) {
            $p = $profiles[$row->profile_id] ?? null;
            $events = array_filter(explode(',', (string) $row->events));

            // «Що зробив» — лише контакт-кліки (навігаційні події йдуть у етап).
            $contactEvents = array_values(array_intersect($engageEvents, $events));
            $actions = array_map(fn ($e) => self::EVENT_LABELS[$e] ?? $e, $contactEvents);

            // Найдальший досягнутий етап (де вийшов).
            $stage = 'viewed';
            if (in_array('info_tab_view', $events, true)) $stage = 'info';
            if (in_array('dossier_open', $events, true)) $stage = 'dossier_open';
            if (in_array('dossier_view', $events, true)) $stage = 'dossier_view';
            if (! empty($contactEvents)) $stage = 'contacted';

            return [
                'name' => $p?->name ?? ('#' . $row->profile_id),
                'slug' => $p?->slug,
                'city' => $p?->city,
                'messages' => array_values(array_filter(explode(',', (string) $row->messages))),
                'views' => (int) $row->views,
                'actions' => $actions,
                'stage' => $stage,
                'last_at' => $row->last_at ? \Illuminate\Support\Carbon::parse($row->last_at)->diffForHumans() : null,
                // Сигнали воронки монетизації (перейшов → підтвердив акаунт → оплатив).
                'clicked' => true, // рядок існує лише за наявності подій → людина точно переходила
                'account' => $p ? $this->accountStage($p, $status['claimed']) : 'none',
                'paid' => $p ? ((bool) $p->is_pro || $status['paid']->contains($p->id)) : false,
            ];
        })->all();

        return view('outreach.stats', [
            'campaigns' => $campaigns,
            'campaign' => $campaign,
            'viewsTotal' => $viewsTotal,
            'lawyersOpened' => $lawyersOpened,
            'clicksTotal' => $clicksTotal,
            'lawyersEngaged' => $lawyersEngaged,
            'engageRate' => $lawyersOpened > 0 ? round($lawyersEngaged / $lawyersOpened * 100) : 0,
            'funnel' => $funnel,
            'byChannel' => $byChannel,
            'perMessage' => $perMessage,
            'byEvent' => $byEvent,
            'perLawyer' => $perLawyer,
        ]);
    }

    /**
     * Окрема статистика саме по email-розсилці: відправки з email_logs,
     * переходи й подальші дії — з profile_events за utm_medium=email.
     */
    public function emailStats(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guardStaff()) {
            return $redirect;
        }

        $utmSource = (string) config('outreach.utm.source', 'outreach');
        $engageEvents = array_values(array_unique(array_merge(
            ProfileAnalyticsService::WEBSITE_CLICK_EVENTS,
            ProfileAnalyticsService::CONTACT_CLICK_EVENTS,
        )));

        $campaigns = ProfileEvent::query()
            ->where('utm_source', $utmSource)
            ->where('utm_medium', 'email')
            ->whereNotNull('utm_campaign')
            ->where('utm_campaign', '!=', '')
            ->distinct()
            ->orderByDesc('utm_campaign')
            ->pluck('utm_campaign')
            ->all();

        $campaign = trim((string) $request->query('campaign', ''));
        if ($campaign !== '' && ! in_array($campaign, $campaigns, true)) {
            $campaign = '';
        }

        // «Хвилі» розсилок: тема листа → мітка + step-id (utm_content). Будується
        // з config/outreach.php, тож нові сценарії підхоплюються автоматично.
        $waves = $this->emailWaves();

        $waveKey = trim((string) $request->query('wave', ''));
        if ($waveKey !== '' && ! array_key_exists($waveKey, $waves)) {
            $waveKey = '';
        }
        $waveSteps = $waveKey !== '' ? $waves[$waveKey]['steps'] : [];

        $sentLogs = EmailLog::query()
            ->where('status', 'sent')
            ->whereIn('subject', array_keys($waves))
            ->when($waveKey !== '', fn ($q) => $q->where('subject', $waveKey))
            ->orderByDesc(DB::raw('coalesce(sent_at, created_at)'))
            ->get(['to_email', 'subject', 'sent_at', 'created_at']);

        $logsByEmail = $sentLogs
            ->groupBy(fn (EmailLog $log) => mb_strtolower(trim((string) $log->to_email)))
            ->filter(fn ($logs, string $email) => $email !== '');

        $emails = $logsByEmail->keys()->values()->all();

        $profiles = empty($emails)
            ? collect()
            : Profile::query()
                ->with('categories:id,name,slug')
                ->whereNotNull('email')
                ->whereIn(DB::raw('lower(email)'), $emails)
                ->get(['id', 'name', 'slug', 'city', 'email', 'rating_avg', 'reviews_count', 'is_owner_verified', 'is_pro'])
                ->map(function (Profile $profile) {
                    $category = $profile->categories->first();
                    $profile->setAttribute('outreach_primary_category_id', $category?->id);
                    $profile->setAttribute('outreach_primary_category_name', $category?->name ?: 'Без категорії');

                    return $profile;
                });

        $categoryOptions = $profiles
            ->map(fn (Profile $profile) => [
                'id' => $profile->getAttribute('outreach_primary_category_id'),
                'name' => $profile->getAttribute('outreach_primary_category_name'),
            ])
            ->filter(fn (array $category) => $category['id'] !== null)
            ->unique('id')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $categoryId = (int) $request->query('category');
        if ($categoryId > 0 && ! collect($categoryOptions)->contains('id', $categoryId)) {
            $categoryId = 0;
        }

        if ($categoryId > 0) {
            $profiles = $profiles
                ->filter(fn (Profile $profile) => (int) $profile->getAttribute('outreach_primary_category_id') === $categoryId)
                ->values();
        }

        $profileIds = $profiles->pluck('id')->map(fn ($id) => (int) $id)->all();
        $selectedEmails = $profiles
            ->pluck('email')
            ->map(fn ($email) => mb_strtolower(trim((string) $email)))
            ->filter()
            ->unique()
            ->values();

        $filteredLogs = $categoryId > 0
            ? $sentLogs->filter(fn (EmailLog $log) => $selectedEmails->contains(mb_strtolower(trim((string) $log->to_email))))->values()
            : $sentLogs;

        $selectedLogsByEmail = $filteredLogs
            ->groupBy(fn (EmailLog $log) => mb_strtolower(trim((string) $log->to_email)))
            ->filter(fn ($logs, string $email) => $email !== '');

        $eventBase = fn () => ProfileEvent::query()
            ->where('utm_source', $utmSource)
            ->where('utm_medium', 'email')
            ->when($campaign !== '', fn ($q) => $q->where('utm_campaign', $campaign))
            // Обрана хвиля: лише переходи з ЇЇ листів (utm_content = stepId_variant).
            ->when($waveSteps !== [], fn ($q) => $q->where(function ($content) use ($waveSteps): void {
                foreach ($waveSteps as $stepId) {
                    $content->orWhere('utm_content', 'like', $stepId . '\\_%');
                }
            }))
            ->whereIn('profile_id', $profileIds);

        $eventRows = empty($profileIds)
            ? collect()
            : $eventBase()
                ->selectRaw(
                    "profile_id,
                     sum(case when event_type = ? then 1 else 0 end) as views,
                     sum(case when event_type <> ? then 1 else 0 end) as actions,
                     max(created_at) as last_at,
                     group_concat(distinct utm_campaign) as campaigns,
                     group_concat(distinct utm_content) as messages,
                     group_concat(distinct event_type) as events",
                    [ProfileAnalyticsService::EVENT_PROFILE_VIEW, ProfileAnalyticsService::EVENT_PROFILE_VIEW]
                )
                ->groupBy('profile_id')
                ->get()
                ->keyBy('profile_id');

        $status = $this->profileStatuses($profileIds);

        $perProfile = $profiles
            ->map(function (Profile $profile) use ($selectedLogsByEmail, $eventRows, $engageEvents, $status) {
                $email = mb_strtolower(trim((string) $profile->email));
                $logs = $selectedLogsByEmail[$email] ?? collect();
                $row = $eventRows[$profile->id] ?? null;
                $events = array_values(array_filter(explode(',', (string) ($row?->events ?? ''))));
                $contactEvents = array_values(array_intersect($engageEvents, $events));

                return [
                    'id' => (int) $profile->id,
                    'name' => (string) $profile->name,
                    'slug' => $profile->slug,
                    'city' => $profile->city,
                    'email' => (string) $profile->email,
                    'category' => (string) $profile->getAttribute('outreach_primary_category_name'),
                    'sent' => $logs->count(),
                    'touches' => $logs->pluck('subject')->unique()->values()->all(),
                    'last_email_at' => $logs->max(fn (EmailLog $log) => $log->sent_at ?: $log->created_at),
                    'clicked' => $row !== null && (int) $row->views > 0,
                    'views' => (int) ($row?->views ?? 0),
                    'actions_count' => (int) ($row?->actions ?? 0),
                    'actions' => array_map(fn ($event) => self::EVENT_LABELS[$event] ?? $event, $contactEvents),
                    'messages' => array_values(array_filter(explode(',', (string) ($row?->messages ?? '')))),
                    'campaigns' => array_values(array_filter(explode(',', (string) ($row?->campaigns ?? '')))),
                    'last_event_at' => $row?->last_at,
                    'account' => $this->accountStage($profile, $status['claimed']),
                    'paid' => (bool) $profile->is_pro || $status['paid']->contains((int) $profile->id),
                ];
            })
            ->sortByDesc(fn (array $profile) => $profile['last_event_at'] ?: $profile['last_email_at'])
            ->values()
            ->all();

        $perCategory = collect($perProfile)
            ->groupBy('category')
            ->map(function ($items, string $category) {
                $sent = (int) $items->sum('sent');
                $profiles = $items->count();
                $clicked = $items->where('clicked', true)->count();

                return [
                    'category' => $category,
                    'sent' => $sent,
                    'profiles' => $profiles,
                    'clicked' => $clicked,
                    'views' => (int) $items->sum('views'),
                    'actions' => (int) $items->sum('actions_count'),
                    'verified' => $items->where('account', 'verified')->count(),
                    'paid' => $items->where('paid', true)->count(),
                    'rate' => $profiles > 0 ? round($clicked / $profiles * 100) : 0,
                ];
            })
            ->sortByDesc('clicked')
            ->values()
            ->all();

        // Зведення «Розсилки» — завжди по ВСІХ хвилях (незалежно від фільтрів),
        // щоб таблиця працювала як навігація між кампаніями.
        $allLogsBySubject = EmailLog::query()
            ->where('status', 'sent')
            ->whereIn('subject', array_keys($waves))
            ->selectRaw('subject, count(*) as sent, count(distinct lower(to_email)) as recipients,'
                . ' min(coalesce(sent_at, created_at)) as first_at, max(coalesce(sent_at, created_at)) as last_at')
            ->groupBy('subject')
            ->get()
            ->keyBy('subject');

        $byWave = [];
        foreach ($waves as $subject => $meta) {
            $log = $allLogsBySubject[$subject] ?? null;
            $clicked = ProfileEvent::query()
                ->where('utm_source', $utmSource)
                ->where('utm_medium', 'email')
                ->when($campaign !== '', fn ($q) => $q->where('utm_campaign', $campaign))
                ->where(function ($content) use ($meta): void {
                    foreach ($meta['steps'] as $stepId) {
                        $content->orWhere('utm_content', 'like', $stepId . '\\_%');
                    }
                    if ($meta['steps'] === []) {
                        $content->whereRaw('1 = 0');
                    }
                })
                ->selectRaw(
                    'count(distinct profile_id) as profiles,'
                    . ' sum(case when event_type = ? then 1 else 0 end) as views,'
                    . ' sum(case when event_type <> ? then 1 else 0 end) as actions',
                    [ProfileAnalyticsService::EVENT_PROFILE_VIEW, ProfileAnalyticsService::EVENT_PROFILE_VIEW]
                )
                ->first();

            // Хвилі без жодного листа й жодного переходу не показуємо.
            if ($log === null && (int) ($clicked->profiles ?? 0) === 0) {
                continue;
            }

            $recipients = (int) ($log->recipients ?? 0);
            $clickedProfilesCount = (int) ($clicked->profiles ?? 0);

            $byWave[] = [
                'subject' => $subject,
                'label' => $meta['label'],
                'sent' => (int) ($log->sent ?? 0),
                'recipients' => $recipients,
                'first_at' => $log?->first_at,
                'last_at' => $log?->last_at,
                'clicked' => $clickedProfilesCount,
                'rate' => $recipients > 0 ? (int) round($clickedProfilesCount / $recipients * 100) : 0,
                'views' => (int) ($clicked->views ?? 0),
                'actions' => (int) ($clicked->actions ?? 0),
                'active' => $waveKey === $subject,
            ];
        }

        usort($byWave, fn (array $a, array $b) => strcmp((string) $b['last_at'], (string) $a['last_at']));

        $clickedProfiles = collect($perProfile)->where('clicked', true)->count();
        $matchedProfiles = count($perProfile);
        $verifiedProfiles = collect($perProfile)->where('account', 'verified')->count();
        $claimedProfiles = collect($perProfile)->where('account', 'claimed')->count();
        $paidProfiles = collect($perProfile)->where('paid', true)->count();
        $unmatchedEmails = $categoryId > 0 ? 0 : max(0, $logsByEmail->count() - $profiles->pluck('email')->map(fn ($email) => mb_strtolower(trim((string) $email)))->unique()->count());

        return view('outreach.email-stats', [
            'campaigns' => $campaigns,
            'campaign' => $campaign,
            'categoryOptions' => $categoryOptions,
            'categoryId' => $categoryId,
            'sentTotal' => $filteredLogs->count(),
            'recipientTotal' => $selectedLogsByEmail->count(),
            'matchedProfiles' => $matchedProfiles,
            'unmatchedEmails' => $unmatchedEmails,
            'clickedProfiles' => $clickedProfiles,
            'clickRate' => $matchedProfiles > 0 ? round($clickedProfiles / $matchedProfiles * 100) : 0,
            'verifiedProfiles' => $verifiedProfiles,
            'claimedProfiles' => $claimedProfiles,
            'paidProfiles' => $paidProfiles,
            'perCategory' => $perCategory,
            'byWave' => $byWave,
            'wave' => $waveKey,
            'stepLabels' => $this->stepLabels(),
            'perProfile' => $perProfile,
            'publicBase' => (string) config('outreach.public_url', 'https://mydovira.com'),
        ]);
    }

    /**
     * «Хвилі» email-розсилок: тема листа → мітка і step-id кроків (utm_content =
     * stepId_variant). Теми обчислюються за тими самими правилами, що в команді
     * розсилки (subjectForTouch), тож нові сценарії з config/outreach.php
     * підхоплюються тут автоматично.
     *
     * @return array<string, array{label: string, steps: array<int, string>}>
     */
    private function emailWaves(): array
    {
        $subjectFor = function (string $sequenceId, int $touch): string {
            if ($sequenceId === 'real_estate') {
                return $touch === 1
                    ? 'Ваш профіль агентства на Dovira'
                    : 'Нагадуємо про ваш профіль агентства на Dovira';
            }

            if ($sequenceId === 'views') {
                return $touch === 2
                    ? 'Нагадуємо: статистика вашого профілю на Dovira'
                    : 'Статистика вашого профілю на Dovira за 30 днів';
            }

            return $touch === 2
                ? 'Нагадуємо про ваш профіль на Dovira'
                : 'Ваш профіль на Dovira';
        };

        $waves = [];

        foreach ((array) config('outreach.sequences', []) as $sequence) {
            $sequenceId = (string) ($sequence['id'] ?? '');
            $sequenceLabel = (string) ($sequence['label'] ?? $sequenceId);

            foreach (array_values((array) ($sequence['steps'] ?? [])) as $index => $step) {
                $subject = $subjectFor($sequenceId, $index + 1);
                $stepId = (string) ($step['id'] ?? '');

                $waves[$subject] ??= ['label' => '', 'steps' => [], '_seq' => [], '_touch' => []];
                if ($stepId !== '' && ! in_array($stepId, $waves[$subject]['steps'], true)) {
                    $waves[$subject]['steps'][] = $stepId;
                }
                if (! in_array($sequenceLabel, $waves[$subject]['_seq'], true)) {
                    $waves[$subject]['_seq'][] = $sequenceLabel;
                }
                if (! in_array($index + 1, $waves[$subject]['_touch'], true)) {
                    $waves[$subject]['_touch'][] = $index + 1;
                }
            }
        }

        foreach ($waves as &$wave) {
            // Стару спільну тему ділить пів десятка сценаріїв — перелік
            // не влазить у селект, згортаємо в узагальнену мітку.
            $sequenceLabel = count($wave['_seq']) > 2 ? 'Базові сценарії' : implode(' / ', $wave['_seq']);
            $wave['label'] = $sequenceLabel . ' · дотик ' . implode(',', $wave['_touch']);
            unset($wave['_seq'], $wave['_touch']);
        }

        return $waves;
    }

    /**
     * Підписи кроків для чипів «з якого листа перейшов»: step-id → «Сценарій · дN».
     *
     * @return array<string, string>
     */
    private function stepLabels(): array
    {
        $labels = [];

        foreach ((array) config('outreach.sequences', []) as $sequence) {
            $sequenceLabel = (string) ($sequence['label'] ?? ($sequence['id'] ?? ''));
            foreach (array_values((array) ($sequence['steps'] ?? [])) as $index => $step) {
                $stepId = (string) ($step['id'] ?? '');
                if ($stepId !== '') {
                    $labels[$stepId] = $sequenceLabel . ' · д' . ($index + 1);
                }
            }
        }

        return $labels;
    }

    private function guardStaff(): ?RedirectResponse
    {
        // Пускаємо, якщо людина залогінена або в адмін-панелі, або на сайті.
        $user = Auth::guard('admin')->user() ?? Auth::guard('web')->user();

        if (! $user) {
            return redirect()->guest(route('filament.admin.auth.login'));
        }

        if (! in_array($user->role, self::ALLOWED_ROLES, true)) {
            abort(403, 'Доступ лише для персоналу.');
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lawyers(): array
    {
        $categoryIds = CategoryHierarchy::categoryIdsFromNames([self::LAWYER_CATEGORY_NAME]);

        if (empty($categoryIds)) {
            return [];
        }

        $columns = [
            'id', 'name', 'slug', 'city', 'status', 'phone', 'email', 'website', 'social_links',
            'rating_avg', 'reviews_count', 'dossier', 'dossier_verdict',
            'owner_user_id', 'is_owner_verified', 'is_pro',
        ];

        if (Schema::hasColumn('profiles', 'outreach_flags')) {
            $columns[] = 'outreach_flags';
        }

        $profiles = Profile::query()
            ->with('owner:id,name,email') // хто ВЛАСНИК профілю (owner_user_id)
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds))
            ->orderByRaw("case when status = 'active' then 0 else 1 end") // активні (робочий лінк) першими
            ->orderBy('name')
            ->get($columns);

        $status = $this->profileStatuses($profiles->pluck('id')->all());

        // Заявки на профілі (хто просив привʼязку) — пакетно, з користувачами.
        $claimsByProfile = ProfileClaim::query()
            ->whereIn('profile_id', $profiles->pluck('id'))
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('profile_id');

        // Публічний домен: посилання в повідомленнях мають вести на живий сайт,
        // а не на APP_URL (локально це 127.0.0.1). Беремо шлях route(...) без хоста
        // й приклеюємо публічну базу.
        $publicBase = (string) config('outreach.public_url', 'https://mydovira.com');

        return $profiles
            ->map(fn (Profile $p): array => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'city' => $p->city ? (string) $p->city : null,
                'active' => $p->status === 'active',
                'url' => $p->slug ? $publicBase . route('profile.show', ['slug' => $p->slug], false) : null,
                'phone' => $p->phone ? (string) $p->phone : null,
                'email' => $p->email ? (string) $p->email : null,
                'telegram' => $this->telegramUrl($p->social_links),
                'website' => $p->website ? (string) $p->website : null,
                'socials' => $this->mapSocials($p->social_links),
                'no_telegram' => (bool) data_get($p->outreach_flags, 'no_telegram'),
                'rating' => round((float) $p->rating_avg, 2),
                'reviews' => (int) $p->reviews_count,
                'has_dossier' => filled($p->dossier),
                'verdict' => $p->dossier_verdict ? (string) $p->dossier_verdict : null, // green|red|yellow
                // Три сигнали воронки монетизації (для позначок біля профілю):
                'clicked' => $status['clicked']->contains($p->id),          // перейшов по нашому посиланню
                'account' => $this->accountStage($p, $status['claimed']),    // none|claimed|verified
                'paid' => (bool) $p->is_pro || $status['paid']->contains($p->id),
                // Привʼязка: хто власник і хто подавав заявки (імʼя + email).
                'owner' => $p->owner ? [
                    'name' => (string) ($p->owner->name ?: '—'),
                    'email' => (string) ($p->owner->email ?: ''),
                ] : null,
                'claims' => ($claimsByProfile[$p->id] ?? collect())
                    ->map(fn (ProfileClaim $c): array => [
                        'name' => (string) ($c->user?->name ?: '—'),
                        'email' => (string) ($c->user?->email ?: ''),
                        'status' => (string) $c->status,
                        'role' => $c->company_role ? (string) $c->company_role : null,
                        'at' => $c->created_at?->diffForHumans(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /** Кількість нерозглянутих заявок — для бейджа на навігації. */
    private function pendingClaimsCount(): int
    {
        return (int) ProfileClaim::query()->where('status', 'pending')->count();
    }

    /**
     * Пакетно рахує три сигнали воронки для списку профілів:
     *  - clicked: є подія з нашої розсилки (utm_source=outreach) → людина перейшла по посиланню;
     *  - claimed: профіль хтось «забрав» (є заявка/власник) → почав підтверджувати акаунт;
     *  - paid:    є активна PRO-підписка → оплатив.
     *
     * @param  array<int, int>  $ids
     * @return array{clicked: \Illuminate\Support\Collection, claimed: \Illuminate\Support\Collection, paid: \Illuminate\Support\Collection}
     */
    private function profileStatuses(array $ids): array
    {
        if (empty($ids)) {
            return ['clicked' => collect(), 'claimed' => collect(), 'paid' => collect()];
        }

        $clicked = ProfileEvent::query()
            ->where('utm_source', (string) config('outreach.utm.source', 'outreach'))
            ->whereIn('profile_id', $ids)
            ->distinct()
            ->pluck('profile_id')
            ->map(fn ($v) => (int) $v);

        $claimed = \App\Models\ProfileClaim::query()
            ->whereIn('profile_id', $ids)
            ->distinct()
            ->pluck('profile_id')
            ->map(fn ($v) => (int) $v);

        $paid = \App\Models\ProSubscription::query()
            ->whereIn('profile_id', $ids)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('started_at')->orWhere('started_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->distinct()
            ->pluck('profile_id')
            ->map(fn ($v) => (int) $v);

        return ['clicked' => $clicked, 'claimed' => $claimed, 'paid' => $paid];
    }

    /**
     * Стадія акаунту: verified (підтвердив права), claimed (подав заявку / є власник),
     * або none (профіль ще нічий).
     */
    private function accountStage(Profile $p, \Illuminate\Support\Collection $claimed): string
    {
        if ($p->is_owner_verified) {
            return 'verified';
        }

        if ($p->owner_user_id || $claimed->contains($p->id)) {
            return 'claimed';
        }

        return 'none';
    }

    /**
     * social_links → [['label' => ..., 'url' => ...]].
     *
     * @return array<int, array{label: string, url: string}>
     */
    private function mapSocials(mixed $socialLinks): array
    {
        $out = [];

        foreach ((array) $socialLinks as $network => $url) {
            if (is_array($url)) {
                $network = $url['network'] ?? $network;
                $url = $url['url'] ?? '';
            }

            $network = mb_strtolower(trim((string) $network));
            $url = trim((string) $url);

            if ($network === '' || $url === '' || $network === 'website') {
                continue;
            }

            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $out[] = [
                'label' => self::SOCIAL_LABELS[$network] ?? ucfirst($network),
                'url' => $url,
            ];
        }

        return $out;
    }

    private function telegramUrl(mixed $socialLinks): ?string
    {
        foreach ((array) $socialLinks as $network => $url) {
            if (is_array($url)) {
                $network = $url['network'] ?? $network;
                $url = $url['url'] ?? '';
            }

            if (mb_strtolower(trim((string) $network)) !== 'telegram') {
                continue;
            }

            $url = trim((string) $url);
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                return $url;
            }
        }

        return null;
    }

    private function normalizeTelegramUrl(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '@')) {
            $value = substr($value, 1);
        }

        if (preg_match('~^t\.me/(.+)$~i', $value)) {
            $value = 'https://' . $value;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $host = mb_strtolower((string) parse_url($value, PHP_URL_HOST));
            return in_array($host, ['t.me', 'telegram.me'], true) ? $value : null;
        }

        return preg_match('~^[A-Za-z0-9_]{5,32}$~', $value)
            ? 'https://t.me/' . $value
            : null;
    }

    private function withTelegramSocialLink(mixed $socialLinks, ?string $telegramUrl): ?array
    {
        $links = (array) $socialLinks;
        $wasList = $links !== [] && array_is_list($links);
        $found = false;

        foreach ($links as $key => $value) {
            if (is_array($value)) {
                $network = mb_strtolower(trim((string) ($value['network'] ?? $key)));
                if ($network !== 'telegram') {
                    continue;
                }

                $found = true;
                if ($telegramUrl === null) {
                    unset($links[$key]);
                } else {
                    $links[$key]['network'] = 'telegram';
                    $links[$key]['url'] = $telegramUrl;
                }

                continue;
            }

            if (mb_strtolower(trim((string) $key)) !== 'telegram') {
                continue;
            }

            $found = true;
            if ($telegramUrl === null) {
                unset($links[$key]);
            } else {
                $links[$key] = $telegramUrl;
            }
        }

        if (! $found && $telegramUrl !== null) {
            if ($links === [] || ! array_is_list($links)) {
                $links['telegram'] = $telegramUrl;
            } else {
                $links[] = ['network' => 'telegram', 'url' => $telegramUrl];
            }
        }

        return $links === [] ? null : ($wasList ? array_values($links) : $links);
    }
}
