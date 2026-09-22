<?php

namespace App\Console\Commands;

use App\Mail\OutreachCampaignEmail;
use App\Models\EmailLog;
use App\Models\Profile;
use App\Support\CategoryHierarchy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Email-кампанія по профілях З ДОСЬЄ (незаявлені профілі з email): кожному —
 * ОДИН лист про його досьє, текст залежить від ВЕРДИКТУ-світлофора
 * (green «чисто» / yellow «є що уточнити» / red «є суттєві факти»). Тон
 * запрошувальний, без тиску: «перегляньте, чи все точно».
 *
 * Захисти: дедуп через email_logs (адреса ніколи не отримає другий лист),
 * opt-out (outreach_opted_out + signed unsubscribe), ліміт і пауза.
 * Спершу --test=адреса шле всі 3 варіанти собі на пошту (без логів і дедупу).
 */
class SendOutreachEmailCampaign extends Command
{
    protected $signature = 'dovira:outreach:email-campaign
        {--category=Адвокати : Назва категорії}
        {--limit=400 : Максимум листів за один запуск}
        {--sleep=500 : Пауза між листами, мс}
        {--sequence=dossier : ID сценарію з config/outreach.php}
        {--reviews-min= : Мінімальна кількість відгуків}
        {--reviews-max= : Максимальна кількість відгуків}
        {--views-min= : Мінімум ОРГАНІЧНИХ переглядів профілю за 30 днів (без utm=outreach)}
        {--rating-below= : Лише профілі з рейтингом нижче цього значення (рейтинг > 0)}
        {--exclude-subject=* : Пропустити адреси, яким уже успішно йшов лист із цією темою (можна кілька)}
        {--min-days-since= : Для дотиків 2/3 — мінімум днів після ПЕРШОГО дотику (пауза між листами)}
        {--name-like=* : Додатковий фільтр по назві профілю; можна кілька}
        {--verdict=* : Фільтр за кольором досьє (red|yellow|green|neutral); можна кілька або через кому}
        {--only-not-visited : Надсилати лише профілям без зафіксованого переходу з outreach-посилань}
        {--touch=1 : Номер email-дотику з послідовності (1, 2 або 3)}
        {--audience=new : Аудиторія: new — кому ще не слали перший лист, emailed — кому слали перший лист, all — усі з дедупом поточного дотику}
        {--resend-current : Повторити поточний дотик навіть якщо такий subject вже є в email_logs}
        {--test= : Надіслати всі 3 варіанти на цю адресу (прев’ю, без запису)}
        {--dry-run : Показати, кому що піде, без надсилання}';

    protected $description = 'Email-розсилка про досьє: 3 варіанти за вердиктом (зелений/жовтий/червоний)';

    /** Скільки листів реально пішло в останньому запуску (для диспетчера daily). */
    public static int $lastSentCount = 0;

    /** Текст інлайн-посилання в тілі (замість голого URL). */
    private const ANCHOR_TEXT = 'Відкрити профіль';

    /** Єдина тема (нейтральна — дала 10/10 на mail-tester). По ній працює дедуп. */
    private const SUBJECT = 'Ваш профіль на Dovira';

    public function handle(): int
    {
        // Команда часто запускається локально (APP_URL=127.0.0.1), а листи йдуть
        // реальним адресатам. Signed-лінки (claim, відписка) через route()/URL::
        // беруть APP_URL — тож примусово генеруємо їх на ПУБЛІЧНОМУ домені,
        // інакше кнопки ведуть на localhost і не працюють.
        $public = rtrim((string) config('outreach.public_url', 'https://mydovira.com'), '/');
        URL::forceRootUrl($public);
        if (str_starts_with($public, 'https://')) {
            URL::forceScheme('https');
        }

        // --category=all — без обмеження за категорією (розсилка по всій базі).
        $categoryOption = trim((string) $this->option('category'));
        $allCategories = mb_strtolower($categoryOption) === 'all';
        $ids = $allCategories ? [] : CategoryHierarchy::categoryIdsFromNames([$categoryOption]);
        if (! $allCategories && empty($ids)) {
            $this->error('Категорію не знайдено.');

            return self::FAILURE;
        }

        if ($test = trim((string) $this->option('test'))) {
            return $this->sendTest($test, $ids);
        }

        $dryRun = (bool) $this->option('dry-run');
        $sequenceId = $this->sequenceId();
        $verdicts = $this->verdictFilters();
        $onlyNotVisited = (bool) $this->option('only-not-visited');
        $resendCurrent = (bool) $this->option('resend-current');
        $touch = $this->touchNumber();
        $audience = $this->audience();
        $firstSubject = $this->subjectForTouch($sequenceId, 1);
        $currentSubject = $this->subjectForTouch($sequenceId, $touch);
        $limit = max(1, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep'));
        $reviewsMin = $this->nullableIntOption('reviews-min');
        $reviewsMax = $this->nullableIntOption('reviews-max');
        $viewsMin = $this->nullableIntOption('views-min');
        $ratingBelowRaw = $this->option('rating-below');
        $ratingBelow = $ratingBelowRaw !== null && trim((string) $ratingBelowRaw) !== ''
            ? (float) $ratingBelowRaw
            : null;
        $nameFilters = $this->nameFilters();

        $query = Profile::query()
            ->where('status', 'active')
            ->whereNull('owner_user_id')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->when($ids !== [], fn ($q) => $q->whereIn('id', fn ($sub) => $sub->select('profile_id')->from('profile_category')->whereIn('category_id', $ids)))
            // Досьє обовʼязкове лише для сценарію «Досьє»; «Перегляди» працює й без
            // нього — профілі без досьє отримують нейтральний варіант листа.
            ->when($sequenceId === 'dossier', fn ($q) => $q->whereNotNull('dossier'))
            ->when($verdicts !== [], fn ($q) => $q->whereIn('dossier_verdict', $verdicts))
            ->when($reviewsMin !== null, fn ($q) => $q->where('reviews_count', '>=', $reviewsMin))
            ->when($reviewsMax !== null, fn ($q) => $q->where('reviews_count', '<=', $reviewsMax))
            // Рейтинг нижче порога, але > 0 — профілі без жодної оцінки не беремо.
            ->when($ratingBelow !== null, fn ($q) => $q->where('rating_avg', '>', 0)->where('rating_avg', '<', $ratingBelow))
            // Тільки профілі з реальною увагою: N+ органічних переглядів за 30 днів.
            // Переходи з наших же outreach-посилань не рахуються — цифра в листі чесна.
            ->when($viewsMin !== null, fn ($q) => $q->whereIn('id', fn ($sub) => $sub
                ->select('profile_id')
                ->from('profile_events')
                ->where('event_type', 'profile_view')
                ->where('created_at', '>=', now()->subDays(30))
                ->where(function ($utm): void {
                    $utm->whereNull('utm_source')
                        ->orWhere('utm_source', '!=', (string) config('outreach.utm.source', 'outreach'));
                })
                ->groupBy('profile_id')
                ->havingRaw('count(*) >= ?', [$viewsMin])))
            ->when($nameFilters !== [], function ($q) use ($nameFilters): void {
                $q->where(function ($names) use ($nameFilters): void {
                    foreach ($nameFilters as $filter) {
                        $names->orWhere('name', 'like', '%' . $filter . '%');
                    }
                });
            })
            ->when($onlyNotVisited, function ($q): void {
                $q->whereDoesntHave('events', function ($events): void {
                    $events
                        ->where('event_type', 'profile_view')
                        ->where('utm_source', (string) config('outreach.utm.source', 'outreach'));
                });
            })
            // Червоні (найтерміновіші) першими, далі жовті, зелені, тоді по id —
            // щоб у обмеженій партії гарантовано пройшли всі 🔴.
            ->orderByRaw("CASE dossier_verdict WHEN 'red' THEN 0 WHEN 'yellow' THEN 1 WHEN 'green' THEN 2 ELSE 3 END")
            ->when($reviewsMax !== null && $reviewsMax > 30, fn ($q) => $q->orderByRaw('CASE WHEN reviews_count <= 30 THEN 0 ELSE 1 END'))
            ->orderByDesc('reviews_count')
            ->orderBy('id');

        $filterLabel = ' | sequence: ' . $sequenceId;
        $filterLabel .= $verdicts !== [] ? ' | verdict: ' . implode(',', $verdicts) : '';
        $filterLabel .= $reviewsMin !== null ? ' | reviews >= ' . $reviewsMin : '';
        $filterLabel .= $reviewsMax !== null ? ' | reviews <= ' . $reviewsMax : '';
        $filterLabel .= $viewsMin !== null ? ' | views30d >= ' . $viewsMin : '';
        $filterLabel .= $ratingBelow !== null ? ' | rating < ' . $ratingBelow : '';
        $filterLabel .= $nameFilters !== [] ? ' | name: ' . implode(',', $nameFilters) : '';
        $filterLabel .= $onlyNotVisited ? ' | без outreach-переходів' : '';
        $filterLabel .= ' | touch: d' . $touch . ' | audience: ' . $audience;
        $filterLabel .= $resendCurrent ? ' | resend-current' : '';
        $this->info(($dryRun ? '[dry-run] ' : '') . 'Кандидатів (email' . $filterLabel . '): ' . (clone $query)->count());

        $sent = 0;
        $skippedDup = 0;
        $skippedOpt = 0;
        $byVariant = ['green' => 0, 'yellow' => 0, 'red' => 0, 'neutral' => 0];

        // Сегмент невеликий (сотні) — беремо одразу з пріоритетним сортуванням.
        foreach ($query->get() as $profile) {
            if ($sent >= $limit) {
                break;
            }

            $email = mb_strtolower(trim((string) $profile->email));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $prefs = (array) ($profile->notification_preferences ?? []);
            if (! empty($prefs['outreach_opted_out'])) {
                $skippedOpt++;

                continue;
            }

            // Захист від подвійного дотику: адресу, якій нещодавно йшов лист
            // іншої кампанії (--exclude-subject), у цю хвилю не беремо.
            $excluded = false;
            foreach ((array) $this->option('exclude-subject') as $excludeSubject) {
                $excludeSubject = trim((string) $excludeSubject);
                if ($excludeSubject !== '' && $this->alreadyEmailed($email, $excludeSubject)) {
                    $excluded = true;
                    break;
                }
            }
            if ($excluded) {
                $skippedDup++;

                continue;
            }

            $hasFirstTouch = $this->alreadyEmailed($email, $firstSubject);
            $hasCurrentTouch = $this->alreadyEmailed($email, $currentSubject);

            // Пауза між дотиками: другий/третій лист не раніше N днів після першого.
            $minDaysSinceRaw = $this->option('min-days-since');
            $minDaysSince = $minDaysSinceRaw !== null && trim((string) $minDaysSinceRaw) !== ''
                ? max(0, (int) $minDaysSinceRaw)
                : null;
            if ($minDaysSince !== null && $touch > 1) {
                $firstTouchAt = $this->firstTouchSentAt($email, $firstSubject);
                if ($firstTouchAt === null || $firstTouchAt->gt(now()->subDays($minDaysSince))) {
                    $skippedDup++;

                    continue;
                }
            }

            if ($audience === 'new' && $hasFirstTouch) {
                $skippedDup++;

                continue;
            }

            if ($audience === 'emailed' && ! $hasFirstTouch) {
                continue;
            }

            if ($hasCurrentTouch && ! $resendCurrent) {
                $skippedDup++;

                continue;
            }

            $variant = $this->variantFor($profile);
            $letter = $this->buildLetter($profile, $variant, $touch, $sequenceId);

            if ($dryRun) {
                $this->line(sprintf('  [%s] #%d %s → %s', $variant, $profile->id, mb_substr($profile->name, 0, 30), $email));
                $sent++;
                $byVariant[$variant]++;

                continue;
            }

            $status = 'sent';
            $error = null;

            try {
                Mail::mailer($this->mailerName())->to($email)->send(new OutreachCampaignEmail(
                    $profile,
                    $letter,
                    URL::signedRoute('review-outreach.unsubscribe', ['profile' => $profile->id]),
                    URL::temporarySignedRoute('profile.claim-link', now()->addDays(30), ['profile' => $profile->id]),
                ));
            } catch (\Throwable $e) {
                $status = 'failed';
                $error = $e->getMessage();
                Log::warning('Outreach campaign email failed.', ['profile_id' => $profile->id, 'error' => $error]);
            }

            // Вичерпано денну квоту провайдера (Resend free = ~100/день) — далі
            // всі спроби провалюватимуться, тож зупиняємось, не логуючи цю й не
            // спамлячи failed (адреса лишиться без запису → повториться завтра).
            if ($status === 'failed' && $error !== null && stripos($error, 'quota') !== false) {
                $this->warn('Досягнуто денну квоту провайдера — зупиняюсь. Решту дошлемо наступного дня.');
                break;
            }

            rescue(fn () => EmailLog::create([
                'user_id' => null,
                'to_email' => $email,
                'subject' => $letter['subject'],
                'status' => $status,
                'error_message' => $error,
                'sent_at' => $status === 'sent' ? now() : null,
            ]));

            if ($status === 'sent') {
                $this->line(sprintf('  [%s] #%d %s → %s', $variant, $profile->id, mb_substr($profile->name, 0, 30), $email));
                $sent++;
                $byVariant[$variant]++;
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s Надіслано: %d (🟢 %d / 🟡 %d / 🔴 %d / нейтр. %d), дублі: %d, opt-out: %d.',
            $dryRun ? '[dry-run]' : 'Готово.',
            $sent,
            $byVariant['green'],
            $byVariant['yellow'],
            $byVariant['red'],
            $byVariant['neutral'],
            $skippedDup,
            $skippedOpt
        ));

        self::$lastSentCount = $sent;

        return self::SUCCESS;
    }

    /** «N раз / рази / разів» за українськими правилами. */
    private function timesWord(int $n): string
    {
        $n100 = $n % 100;
        $n10 = $n % 10;
        if ($n100 >= 11 && $n100 <= 14) {
            return $n . ' разів';
        }
        if ($n10 === 1) {
            return $n . ' раз';
        }
        if ($n10 >= 2 && $n10 <= 4) {
            return $n . ' рази';
        }

        return $n . ' разів';
    }

    /** Покази картки профілю в каталозі/пошуку за 30 днів (event card_impression). */
    private function cardImpressions30d(Profile $profile): int
    {
        return (int) rescue(fn (): int => DB::table('profile_events')
            ->where('profile_id', $profile->id)
            ->where('event_type', \App\Services\ProfileAnalyticsService::EVENT_CARD_IMPRESSION)
            ->where('created_at', '>=', now()->subDays(30))
            ->count(), 0);
    }

    /** Коли адресату пішов ПЕРШИЙ дотик (для паузи --min-days-since). */
    private function firstTouchSentAt(string $email, string $firstSubject): ?\Illuminate\Support\Carbon
    {
        $value = rescue(fn () => EmailLog::query()
            ->where('to_email', $email)
            ->where('subject', $firstSubject)
            ->where('status', 'sent')
            ->min(DB::raw('coalesce(sent_at, created_at)')), null);

        return $value ? \Illuminate\Support\Carbon::parse($value) : null;
    }

    /**
     * Прев’ю: усі 3 варіанти вердикту на власну пошту, на реальному профілі.
     *
     * @param  array<int, int>  $ids
     */
    private function sendTest(string $to, array $ids): int
    {
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("Невалідна тестова адреса: {$to}");

            return self::FAILURE;
        }

        $variants = $this->verdictFilters();
        if ($variants === []) {
            $variants = ['green', 'yellow', 'red'];
        }
        $touch = $this->touchNumber();
        $sequenceId = $this->sequenceId();

        $this->info("Тест на {$to} — sequence {$sequenceId}, touch {$touch}, варіанти вердикту: " . implode(', ', $variants));

        foreach ($variants as $variant) {
            // Беремо реальний профіль саме з таким вердиктом (щоб лінк був живий).
            $sample = Profile::query()
                ->where('status', 'active')
                ->when($sequenceId === 'dossier', fn ($q) => $q
                    ->whereNotNull('dossier')
                    ->where('dossier_verdict', $variant))
                ->when($ids !== [], fn ($q) => $q->whereIn('id', fn ($sub) => $sub->select('profile_id')->from('profile_category')->whereIn('category_id', $ids)))
                ->orderBy('id')
                ->first();

            if (! $sample) {
                $this->warn("  {$variant}: немає прикладу профілю — пропущено.");

                continue;
            }

            $letter = $this->buildLetter($sample, $variant, $touch, $sequenceId);
            $letter['subject'] = '[ТЕСТ ' . $variant . '] ' . $letter['subject'];

            try {
                // У тесті claim-кнопку не робимо живою (клік реально забрав би
                // приклад-профіль). У бойовій розсилці claimUrl передається.
                Mail::mailer($this->mailerName())->to($to)->send(new OutreachCampaignEmail(
                    $sample,
                    $letter,
                    URL::signedRoute('review-outreach.unsubscribe', ['profile' => $sample->id]),
                    '',
                ));
                $this->line("  ✓ {$variant} (приклад #{$sample->id} {$sample->name}) → {$to}");
            } catch (\Throwable $e) {
                $this->error("  ✗ {$variant}: " . $e->getMessage());
            }
        }

        $this->info('Готово. Перевір пошту — це прев’ю, у базу нічого не записано, дедуп не зачеплено.');

        return self::SUCCESS;
    }

    /** Мейлер кампанії: окремий (Resend) з config/outreach, інакше дефолтний. */
    private function mailerName(): string
    {
        return (string) (config('outreach.mailer') ?: config('mail.default'));
    }

    private function variantFor(Profile $profile): string
    {
        $verdict = (string) $profile->dossier_verdict;

        return in_array($verdict, ['green', 'yellow', 'red'], true) ? $verdict : 'neutral';
    }

    /**
     * @return array<int, string>
     */
    private function verdictFilters(): array
    {
        return collect((array) $this->option('verdict'))
            ->flatMap(fn ($value) => explode(',', (string) $value))
            ->map(fn ($value) => trim(mb_strtolower((string) $value)))
            ->filter(fn ($value) => in_array($value, ['red', 'yellow', 'green', 'neutral'], true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Тіло дотику з config/outreach.php. Якщо у кроку є variants — беремо
     * вердиктний текст; інакше загальний body.
     */
    private function sequenceBody(string $sequenceId, string $variant, int $touch = 1): string
    {
        $step = null;
        foreach ((array) config('outreach.sequences', []) as $sequence) {
            if (($sequence['id'] ?? null) === $sequenceId) {
                $step = collect((array) ($sequence['steps'] ?? []))
                    ->values()
                    ->get($touch - 1);
                break;
            }
        }

        if (! is_array($step)) {
            return 'Доброго дня! На вашій сторінці Dovira є досьє: {досьє}';
        }

        return (string) ($step['variants'][$variant] ?? $step['body'] ?? '');
    }

    /**
     * Збирає БЕЗПЕЧНИЙ HTML тіла: текст екранується, {досьє} → інлайн-<a>
     * з підписом «Перевірити досьє» на блок досьє профілю (з UTM).
     *
     * @return array{subject: string, body_html: string, body_text: string}
     */
    private function buildLetter(Profile $profile, string $variant, int $touch = 1, string $sequenceId = 'dossier'): array
    {
        $body = $this->sequenceBody($sequenceId, $variant, $touch);
        $stepId = $this->sequenceStepId($sequenceId, $touch);

        $publicBase = rtrim((string) config('outreach.public_url', 'https://mydovira.com'), '/');
        // Пряме посилання на профіль (блок досьє) з UTM — щоб перехід точно
        // рахувався у статистиці (medium=email). Коротке /s/ лишили для SMS.
        $profilePath = route('profile.show', ['slug' => $profile->slug], false);
        $utm = http_build_query([
            'utm_source' => (string) config('outreach.utm.source', 'outreach'),
            'utm_medium' => 'email',
            'utm_campaign' => (string) config('outreach.campaign', 'lawyers'),
            'utm_content' => $stepId . '_' . $variant,
        ]);
        $dossierUrl = $publicBase . $profilePath . '?' . $utm . '#profile-dossier';

        // Гарна кнопка-CTA (з нового рядка), а не голий підкреслений лінк.
        $anchor = '<br><a href="' . e($dossierUrl) . '" '
            . 'style="display:inline-block;margin-top:6px;background:#2f6df4;color:#ffffff;text-decoration:none;'
            . 'font-weight:700;font-size:15px;line-height:1;padding:13px 24px;border-radius:12px;">'
            . e(self::ANCHOR_TEXT) . ' →</a>';

        $name = (string) $profile->name;
        // Лічимо ліниво: {перегляди} є лише в сценарії views — іншим листам
        // зайвий запит на профіль не потрібен.
        $views = str_contains($body, '{перегляди}')
            ? $this->timesWord($this->organicViews30d($profile))
            : '';
        // Покази картки в каталозі/пошуку: додаємо реченням, лише коли число
        // переконливе (>=20) — слабка цифра ослаблює лист, а не підсилює.
        $impressionsLine = '';
        if (str_contains($body, '{покази_рядок}')) {
            $impressions = $this->cardImpressions30d($profile);
            $impressionsLine = $impressions >= 20
                ? ' Ще ' . $this->timesWord($impressions) . ' вашу картку бачили у каталозі та пошуку Dovira.'
                : '';
        }

        // HTML: екрануємо шаблон, тоді підставляємо НЕекранований інлайн-лінк.
        $html = strtr(e($body), [
            '{імя}' => e($name),
            '{перегляди}' => e($views),
            '{покази_рядок}' => e($impressionsLine),
            '{досьє}' => $anchor,
            '{посилання}' => $anchor,
            '{профіль}' => $anchor,
        ]);

        // Текст: підпис + URL у дужках.
        $text = strtr($body, [
            '{імя}' => $name,
            '{перегляди}' => $views,
            '{покази_рядок}' => $impressionsLine,
            '{досьє}' => self::ANCHOR_TEXT . ': ' . $dossierUrl,
            '{посилання}' => $dossierUrl,
            '{профіль}' => $dossierUrl,
        ]);

        return [
            'subject' => $this->subjectForTouch($sequenceId, $touch),
            'body_html' => $html,
            'body_text' => $text,
        ];
    }

    private function alreadyEmailed(string $email, string $subject): bool
    {
        // Лише УСПІШНІ відправки — інакше збій (квота/тимчасова помилка)
        // назавжди блокував би повторну спробу цій адресі.
        // Тема з «%» — шаблон (LIKE): для кампаній, чия тема містить назву
        // профілю (top-profiles), точний збіг неможливий.
        return (bool) rescue(fn (): bool => EmailLog::query()
            ->where('to_email', $email)
            ->where('subject', str_contains($subject, '%') ? 'like' : '=', $subject)
            ->where('status', 'sent')
            ->exists(), false);
    }

    private function touchNumber(): int
    {
        $touch = (int) $this->option('touch');

        return in_array($touch, [1, 2, 3], true) ? $touch : 1;
    }

    private function sequenceId(): string
    {
        $sequence = trim((string) $this->option('sequence'));

        foreach ((array) config('outreach.sequences', []) as $item) {
            if (($item['id'] ?? null) === $sequence) {
                return $sequence;
            }
        }

        return 'dossier';
    }

    private function sequenceStepId(string $sequenceId, int $touch): string
    {
        foreach ((array) config('outreach.sequences', []) as $sequence) {
            if (($sequence['id'] ?? null) !== $sequenceId) {
                continue;
            }

            $step = collect((array) ($sequence['steps'] ?? []))
                ->values()
                ->get($touch - 1);

            if (is_array($step) && filled($step['id'] ?? null)) {
                return (string) $step['id'];
            }
        }

        return 'd' . $touch;
    }

    private function nullableIntOption(string $name): ?int
    {
        $value = $this->option($name);

        return filled($value) ? (int) $value : null;
    }

    /**
     * @return array<int, string>
     */
    private function nameFilters(): array
    {
        return collect((array) $this->option('name-like'))
            ->flatMap(fn ($value) => explode(',', (string) $value))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function audience(): string
    {
        $audience = trim(mb_strtolower((string) $this->option('audience')));

        return in_array($audience, ['new', 'emailed', 'all'], true) ? $audience : 'new';
    }

    private function subjectForTouch(string $sequenceId, int $touch): string
    {
        if ($sequenceId === 'real_estate') {
            return $touch === 1
                ? 'Ваш профіль агентства на Dovira'
                : 'Нагадуємо про ваш профіль агентства на Dovira';
        }

        // Окрема тема для сценарію «Перегляди»: дедуп по subject не конфліктує
        // з попередніми кампаніями «Ваш профіль на Dovira» — тим самим адресатам
        // можна чесно писати з новим кутом (цифра переглядів). Тема фіксована,
        // без числа: число в темі ламало б дедуплікацію повторних запусків.
        if ($sequenceId === 'views') {
            return $touch === 2
                ? 'Нагадуємо: статистика вашого профілю на Dovira'
                : 'Статистика вашого профілю на Dovira за 30 днів';
        }

        return $touch === 2
            ? 'Нагадуємо про ваш профіль на Dovira'
            : self::SUBJECT;
    }

    /**
     * Органічні перегляди профілю за 30 днів — та сама формула, що у фільтрі
     * --views-min: без переходів із наших outreach-посилань, щоб цифра в листі
     * була чесною і збігалася з умовою відбору.
     */
    private function organicViews30d(Profile $profile): int
    {
        return (int) rescue(fn (): int => DB::table('profile_events')
            ->where('profile_id', $profile->id)
            ->where('event_type', 'profile_view')
            ->where('created_at', '>=', now()->subDays(30))
            ->where(function ($utm): void {
                $utm->whereNull('utm_source')
                    ->orWhere('utm_source', '!=', (string) config('outreach.utm.source', 'outreach'));
            })
            ->count(), 0);
    }
}
