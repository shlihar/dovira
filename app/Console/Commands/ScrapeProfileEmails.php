<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Support\CategoryHierarchy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Дозаповнює порожній email профілю, парсячи його ВЛАСНИЙ сайт (mailto: і
 * сторінки «Контакти»). Джерело — Google Maps — email не віддає, тож для
 * профілів із сайтом це єдиний спосіб дістати пошту без AI-токенів.
 *
 * Приклад: dovira:scrape-emails --category=Адвокати --only-dossier --dry-run --limit=20
 */
class ScrapeProfileEmails extends Command
{
    protected $signature = 'dovira:scrape-emails
        {--category=Адвокати : Назва категорії}
        {--only-dossier : Лише профілі з досьє}
        {--only-unclaimed : Лише незаявлені профілі (owner null)}
        {--reviews-min= : Мінімальна кількість відгуків}
        {--reviews-max= : Максимальна кількість відгуків}
        {--name-like=* : Додатковий фільтр по назві профілю; можна кілька}
        {--limit=0 : Скільки профілів опрацювати (0 — усі)}
        {--sleep=400 : Пауза між сайтами, мс}
        {--dry-run : Не писати в БД, лише показати знахідки}';

    protected $description = 'Парсить контактний email із сайтів профілів і дозаписує в порожнє поле email';

    /** Домени-сміття, які трапляються в розмітці, але не є контактом бізнесу. */
    private const JUNK_DOMAINS = [
        'example.com', 'example.org', 'domain.com', 'email.com', 'yourdomain.com',
        'sentry.io', 'wixpress.com', 'wix.com', 'godaddy.com', 'jsdelivr.net',
        'w3.org', 'schema.org', 'googleapis.com', 'gstatic.com', 'cloudflare.com',
        'sentry-next.wixpress.com', 'placeholder.com', 'test.com', 'mail.example.com',
    ];

    /** Локальні частини, що виказують шаблон/приклад, а не реальну адресу. */
    private const JUNK_LOCAL = ['your', 'email', 'name', 'user', 'username', 'example', 'mail', 'info@example'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep'));
        $reviewsMin = $this->nullableIntOption('reviews-min');
        $reviewsMax = $this->nullableIntOption('reviews-max');
        $nameFilters = $this->nameFilters();

        // --category=all — збір по всій базі без обмеження категорією.
        $categoryOption = trim((string) $this->option('category'));
        $allCategories = mb_strtolower($categoryOption) === 'all';
        $ids = $allCategories ? [] : CategoryHierarchy::categoryIdsFromNames([$categoryOption]);
        if (! $allCategories && empty($ids)) {
            $this->error('Категорію не знайдено.');

            return self::FAILURE;
        }

        $query = Profile::query()
            ->where('status', 'active')
            ->when($ids !== [], fn ($q) => $q->whereIn('id', fn ($sub) => $sub->select('profile_id')->from('profile_category')->whereIn('category_id', $ids)))
            ->where(fn ($q) => $q->whereNull('email')->orWhere('email', ''))
            ->whereNotNull('website')
            ->where('website', '!=', '')
            ->when($this->option('only-dossier'), fn ($q) => $q->whereNotNull('dossier'))
            ->when($this->option('only-unclaimed'), fn ($q) => $q->whereNull('owner_user_id'))
            ->when($reviewsMin !== null, fn ($q) => $q->where('reviews_count', '>=', $reviewsMin))
            ->when($reviewsMax !== null, fn ($q) => $q->where('reviews_count', '<=', $reviewsMax))
            ->when($nameFilters !== [], function ($q) use ($nameFilters): void {
                $q->where(function ($names) use ($nameFilters): void {
                    foreach ($nameFilters as $filter) {
                        $names->orWhere('name', 'like', '%' . $filter . '%');
                    }
                });
            })
            ->when($reviewsMax !== null && $reviewsMax > 30, fn ($q) => $q->orderByRaw('CASE WHEN reviews_count <= 30 THEN 0 ELSE 1 END'))
            ->orderByDesc('reviews_count')
            ->orderBy('id');

        $total = (clone $query)->count();
        $this->info(($dryRun ? '[dry-run] ' : '') . "Кандидатів (порожній email + є сайт): {$total}");

        $processed = 0;
        $found = 0;

        // НЕ chunkById: пріоритетне сортування за reviews_count ламає його
        // id-курсор, і частина кандидатів мовчки пропускається. Сегмент малий
        // (сотні рядків) — беремо одним запитом лише потрібні колонки.
        (function () use ($query, &$processed, &$found, $dryRun, $limit, $sleepMs): void {
            foreach ($query->get(['id', 'name', 'website']) as $profile) {
                if ($limit > 0 && $processed >= $limit) {
                    return;
                }
                $processed++;

                $email = $this->scrapeEmail((string) $profile->website);
                if ($email === null) {
                    $this->line(sprintf('  #%d %s — %s → —', $profile->id, Str::limit($profile->name, 24), Str::limit($profile->website, 32)));
                    if ($sleepMs > 0) {
                        usleep($sleepMs * 1000);
                    }

                    continue;
                }

                $found++;
                $this->line(sprintf('  #%d %s → %s', $profile->id, Str::limit($profile->name, 24), $email));

                if (! $dryRun) {
                    // Пишемо лише якщо поле й досі порожнє (не перетираємо ручні дані).
                    rescue(fn () => Profile::query()
                        ->whereKey($profile->id)
                        ->where(fn ($q) => $q->whereNull('email')->orWhere('email', ''))
                        ->update(['email' => $email]));
                }

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }
        })();

        $this->newLine();
        $this->info(sprintf(
            '%s Опрацьовано: %d, знайдено email: %d (%.0f%%).',
            $dryRun ? '[dry-run]' : 'Готово.',
            $processed,
            $found,
            $processed > 0 ? $found / $processed * 100 : 0
        ));

        return self::SUCCESS;
    }

    /**
     * Тягне головну + типові сторінки контактів і повертає найкращий email.
     */
    private function scrapeEmail(string $website): ?string
    {
        $base = $this->normalizeUrl($website);
        if ($base === null) {
            return null;
        }

        $host = preg_replace('/^www\./', '', (string) parse_url($base, PHP_URL_HOST));

        // Головна + типові контактні шляхи. Зупиняємось на першому знайденому
        // email (зазвичай уже на головній) — інакше 430 сайтів парсились би години.
        $paths = ['', '/contacts', '/kontakty', '/contact'];

        $candidates = [];
        foreach ($paths as $path) {
            $html = $this->fetch($base . $path);
            if ($html === null) {
                continue;
            }

            foreach ($this->extractEmails($html) as $email) {
                $candidates[$email] = ($candidates[$email] ?? 0) + 1;
            }

            // Знайшли хоч одну правдоподібну адресу — далі не ходимо.
            if ($this->pickBest($candidates, $host) !== null) {
                break;
            }
        }

        return $this->pickBest($candidates, $host);
    }

    private function fetch(string $url): ?string
    {
        return rescue(function () use ($url): ?string {
            $res = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; DoviraBot/1.0; +https://mydovira.com)',
                'Accept' => 'text/html,application/xhtml+xml',
            ])
                ->withOptions(['allow_redirects' => true, 'verify' => false])
                ->connectTimeout(5)
                ->timeout(8)
                ->get($url);

            if (! $res->successful()) {
                return null;
            }

            $body = (string) $res->body();

            return $body !== '' ? Str::limit($body, 400000, '') : null;
        }, null, false);
    }

    /**
     * @return array<int, string>
     */
    private function extractEmails(string $html): array
    {
        $emails = [];

        // mailto: — найнадійніше джерело.
        if (preg_match_all('/mailto:([^"\'\s>?]+)/i', $html, $m)) {
            foreach ($m[1] as $raw) {
                $emails[] = rawurldecode($raw);
            }
        }

        // Звичайні входження у тексті.
        if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $html, $m2)) {
            foreach ($m2[0] as $raw) {
                $emails[] = $raw;
            }
        }

        return collect($emails)
            ->map(fn (string $e) => mb_strtolower(trim($e)))
            ->filter(fn (string $e) => $this->isPlausible($e))
            ->unique()
            ->values()
            ->all();
    }

    private function isPlausible(string $email): bool
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        [$local, $domain] = explode('@', $email, 2);

        // Файли/ассети, що випадково матчаться (foo@2x.png тощо).
        if (preg_match('/\.(png|jpe?g|gif|svg|webp|ico|css|js|woff2?|ttf)$/i', $email)) {
            return false;
        }
        if (in_array($domain, self::JUNK_DOMAINS, true)) {
            return false;
        }
        if (in_array($local, self::JUNK_LOCAL, true)) {
            return false;
        }
        // Хеш-подібні sentry/wix ідентифікатори.
        if (strlen($local) > 40) {
            return false;
        }

        return true;
    }

    /**
     * Найкраща адреса: спершу з доменом сайту, потім перша валідна.
     *
     * @param  array<string, int>  $candidates
     */
    private function pickBest(array $candidates, string $host): ?string
    {
        if (empty($candidates)) {
            return null;
        }

        $emails = array_keys($candidates);

        if ($host !== '') {
            foreach ($emails as $email) {
                $domain = Str::after($email, '@');
                if ($domain === $host || Str::endsWith($domain, '.' . $host) || Str::endsWith($host, '.' . $domain)) {
                    return $email;
                }
            }
        }

        return $emails[0];
    }

    private function normalizeUrl(string $website): ?string
    {
        $website = trim($website);
        if ($website === '') {
            return null;
        }
        if (! Str::startsWith($website, ['http://', 'https://'])) {
            $website = 'https://' . ltrim($website, '/');
        }
        if (! filter_var($website, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = parse_url($website, PHP_URL_SCHEME);
        $host = parse_url($website, PHP_URL_HOST);
        if (! $host) {
            return null;
        }

        return $scheme . '://' . $host;
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
}
