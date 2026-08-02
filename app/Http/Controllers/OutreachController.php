<?php

namespace App\Http\Controllers;

use App\Models\Profile;
use App\Models\ProfileEvent;
use App\Services\ProfileAnalyticsService;
use App\Support\CategoryHierarchy;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

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

    public function index(): View|RedirectResponse
    {
        if ($redirect = $this->guardStaff()) {
            return $redirect;
        }

        return view('outreach.index', [
            'lawyers' => $this->lawyers(),
            'messages' => array_values(config('outreach.messages', [])),
            'utm' => [
                'source' => (string) config('outreach.utm.source', 'outreach'),
                'medium' => (string) config('outreach.utm.medium', 'dm'),
                'campaign' => (string) config('outreach.campaign', 'lawyers'),
            ],
        ]);
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

        // ── По повідомленнях ──
        $opensByMsg = $base()->where('event_type', ProfileAnalyticsService::EVENT_PROFILE_VIEW)
            ->selectRaw('utm_content, count(*) as views, count(distinct profile_id) as lawyers')
            ->groupBy('utm_content')->get()->keyBy('utm_content');
        $engByMsg = $base()->whereIn('event_type', $engageEvents)
            ->selectRaw('utm_content, count(*) as clicks, count(distinct profile_id) as lawyers')
            ->groupBy('utm_content')->get()->keyBy('utm_content');

        $perMessage = [];
        foreach (config('outreach.messages', []) as $m) {
            $id = (string) $m['id'];
            $opened = (int) ($opensByMsg[$id]->lawyers ?? 0);
            $engaged = (int) ($engByMsg[$id]->lawyers ?? 0);
            $perMessage[] = [
                'id' => $id,
                'label' => (string) $m['label'],
                'opened' => $opened,
                'views' => (int) ($opensByMsg[$id]->views ?? 0),
                'engaged' => $engaged,
                'clicks' => (int) ($engByMsg[$id]->clicks ?? 0),
                'rate' => $opened > 0 ? round($engaged / $opened * 100) : 0,
            ];
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
            'perMessage' => $perMessage,
            'byEvent' => $byEvent,
            'perLawyer' => $perLawyer,
        ]);
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

        $profiles = Profile::query()
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds))
            ->orderByRaw("case when status = 'active' then 0 else 1 end") // активні (робочий лінк) першими
            ->orderBy('name')
            ->get([
                'id', 'name', 'slug', 'city', 'status', 'phone', 'email', 'website', 'social_links',
                'rating_avg', 'reviews_count', 'dossier', 'dossier_verdict',
                'owner_user_id', 'is_owner_verified', 'is_pro',
            ]);

        $status = $this->profileStatuses($profiles->pluck('id')->all());

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
                'website' => $p->website ? (string) $p->website : null,
                'socials' => $this->mapSocials($p->social_links),
                'rating' => round((float) $p->rating_avg, 2),
                'reviews' => (int) $p->reviews_count,
                'has_dossier' => filled($p->dossier),
                'verdict' => $p->dossier_verdict ? (string) $p->dossier_verdict : null, // green|red|yellow
                // Три сигнали воронки монетизації (для позначок біля профілю):
                'clicked' => $status['clicked']->contains($p->id),          // перейшов по нашому посиланню
                'account' => $this->accountStage($p, $status['claimed']),    // none|claimed|verified
                'paid' => (bool) $p->is_pro || $status['paid']->contains($p->id),
            ])
            ->values()
            ->all();
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
}
