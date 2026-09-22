<?php

namespace App\Services;

use App\Models\Profile;
use App\Models\ProfileReview;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Імпорт відгуків про адвокатів з uadvokat.com.ua.
 *
 * Профілі з цього довідника вже є в базі (див. UadvokatImportService), і кожен
 * має ProfileDataSource з source_type = 'uadvokat_import' та URL картки. Відгуки
 * лежать на окремій сторінці картки: {картка без .html}/reviews.html, далі
 * reviews-1.html, reviews-2.html... по 10 відгуків на сторінку. Якщо відгуків
 * немає — сторінка віддає 404, тож перевірка коштує один запит на профіль.
 */
class UadvokatReviewImportService
{
    private const BASE_URL = 'https://uadvokat.com.ua';

    public const SOURCE_TYPE = 'uadvokat';

    public const VERIFICATION_TYPE = 'external_uadvokat_import';

    /** Пауза між HTTP-запитами, мс. */
    private const REQUEST_DELAY_MS = 400;

    /** Відгуків на сторінці пагінації сайту. */
    private const PER_PAGE = 10;

    public function requestDelayMs(): int
    {
        return self::REQUEST_DELAY_MS;
    }

    /**
     * Глобальна стрічка відгуків сайту: /reviews.html, /reviews-1.html, …
     * Дає ті самі відгуки повним текстом плюс посилання на картку адвоката,
     * тому обійти стрічку дешевше, ніж стукати в кожен із тисяч профілів.
     */
    public function feedUrl(int $pageIndex = 0): string
    {
        return $pageIndex <= 0
            ? self::BASE_URL . '/reviews.html'
            : self::BASE_URL . '/reviews-' . $pageIndex . '.html';
    }

    /**
     * @return array<int, array{author:string, rating:int, date:?string, body:string, source_url:string, lawyer_url:string, lawyer_name:string}>|null
     *                                                                   null — сторінки немає (кінець стрічки)
     */
    public function fetchFeedPage(int $pageIndex): ?array
    {
        $url = $this->feedUrl($pageIndex);
        $html = $this->fetchHtml($url);
        if ($html === null) {
            return null;
        }

        return $this->parseFeedBlocks($html, $url);
    }

    /**
     * @return array<int, array{author:string, rating:int, date:?string, body:string, source_url:string, lawyer_url:string, lawyer_name:string}>
     */
    public function parseFeedBlocks(string $html, string $sourceUrl): array
    {
        $chunks = explode('<div class="review_v"', $html);
        array_shift($chunks);

        $reviews = [];
        foreach ($chunks as $chunk) {
            $chunk = explode('<div class="pagination"', $chunk)[0];

            $body = $this->matchFirst('~class="text_review_300"[^>]*>(.*?)</div>~s', $chunk);
            $href = $this->matchFirst('~<a href="([^"]+)"~', $chunk);
            if ($body === null || $href === null) {
                continue;
            }

            $body = $this->cleanText($body);
            $rating = substr_count($chunk, 'fa fa-star"');
            if ($body === '' || $rating < 1 || $rating > 5) {
                continue;
            }

            // У стрічці автор іде окремим <font> («від Ангелина»), а <span> зайнятий іменем адвоката.
            $author = $this->cleanText((string) $this->matchFirst('~<font[^>]*>(.*?)</font>~s', $chunk));
            $author = trim(preg_replace('~^(від|от)\s+~ui', '', $author) ?? $author);

            $reviews[] = [
                'author' => $author !== '' ? $author : 'Користувач',
                'rating' => $rating,
                'date' => $this->normalizeDate($this->matchFirst('~<time>(.*?)</time>~s', $chunk)),
                'body' => $body,
                'source_url' => $sourceUrl,
                'lawyer_url' => $this->normalizeLawyerUrl($href),
                'lawyer_name' => $this->cleanLawyerName((string) $this->matchFirst('~<a href="[^"]+"[^>]*>(.*?)</a>~s', $chunk)),
            ];
        }

        return $reviews;
    }

    /** Приводить посилання на картку до вигляду, який зберігає ProfileDataSource. */
    public function normalizeLawyerUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '/')) {
            $url = self::BASE_URL . $url;
        }

        return preg_replace('~^http://~i', 'https://', $url) ?? $url;
    }

    /**
     * URL першої сторінки відгуків для картки адвоката.
     * https://…/ivanov-ivan.html → https://…/ivanov-ivan/reviews.html
     */
    public function reviewsUrlFor(string $lawyerUrl, int $pageIndex = 0): ?string
    {
        $lawyerUrl = trim($lawyerUrl);
        if ($lawyerUrl === '' || ! str_contains($lawyerUrl, 'uadvokat.com.ua')) {
            return null;
        }

        $base = preg_replace('~\.html$~i', '', rtrim($lawyerUrl, '/'));
        if (! is_string($base) || $base === '') {
            return null;
        }

        return $pageIndex <= 0
            ? $base . '/reviews.html'
            : $base . '/reviews-' . $pageIndex . '.html';
    }

    /**
     * Збирає всі відгуки картки, проходячи пагінацію.
     *
     * @return array{reviews: array<int, array{author:string, rating:int, date:?string, body:string, source_url:string}>, pages_visited:int, declared_count:?int, missing:bool}
     */
    public function fetchLawyerReviews(string $lawyerUrl, int $maxPages = 30): array
    {
        $reviews = [];
        $pagesVisited = 0;
        $declaredCount = null;
        $missing = false;

        for ($pageIndex = 0; $pageIndex < max(1, $maxPages); $pageIndex++) {
            $url = $this->reviewsUrlFor($lawyerUrl, $pageIndex);
            if ($url === null) {
                break;
            }

            $html = $this->fetchHtml($url);
            if ($html === null) {
                if ($pageIndex === 0) {
                    $missing = true;
                }
                break;
            }

            $pagesVisited++;
            if ($declaredCount === null) {
                $declaredCount = $this->extractDeclaredReviewCount($html);
            }

            $pageReviews = $this->parseReviewBlocks($html, $url);
            if ($pageReviews === []) {
                break;
            }

            foreach ($pageReviews as $review) {
                $reviews[] = $review;
            }

            // Неповна сторінка = остання; зайвий запит не робимо.
            if (count($pageReviews) < self::PER_PAGE) {
                break;
            }

            usleep(self::REQUEST_DELAY_MS * 1000);
        }

        return [
            'reviews' => $reviews,
            'pages_visited' => $pagesVisited,
            'declared_count' => $declaredCount,
            'missing' => $missing,
        ];
    }

    /**
     * @return array<int, array{author:string, rating:int, date:?string, body:string, source_url:string}>
     */
    public function parseReviewBlocks(string $html, string $sourceUrl): array
    {
        $chunks = explode('<div class="review_v"', $html);
        array_shift($chunks);

        $reviews = [];
        foreach ($chunks as $chunk) {
            // Пагінація і форма «додати відгук» ідуть після останнього блоку.
            $chunk = explode('<div class="pagination"', $chunk)[0];
            $chunk = explode('<div class="borderdiv"', $chunk)[0];

            $body = $this->matchFirst('~class="text_review_300"[^>]*>(.*?)</div>~s', $chunk);
            if ($body === null) {
                continue;
            }

            $body = $this->cleanText($body);
            if ($body === '') {
                continue;
            }

            $rating = substr_count($chunk, 'fa fa-star"');
            if ($rating < 1 || $rating > 5) {
                continue;
            }

            $author = $this->cleanText((string) $this->matchFirst('~<span>(.*?)</span>~s', $chunk));

            $reviews[] = [
                'author' => $author !== '' ? $author : 'Користувач',
                'rating' => $rating,
                'date' => $this->normalizeDate($this->matchFirst('~<time>(.*?)</time>~s', $chunk)),
                'body' => $body,
                'source_url' => $sourceUrl,
            ];
        }

        return $reviews;
    }

    /**
     * Записує зібрані відгуки на профіль. Ідемпотентно: повтор запуску нічого не дублює.
     *
     * @param  array<int, array{author:string, rating:int, date:?string, body:string, source_url:string}>  $reviews
     * @return array{created:int, updated:int, skipped_short:int, skipped_duplicate:int}
     */
    public function storeReviews(Profile $profile, array $reviews, string $status = 'published', int $minLength = 0): array
    {
        $created = 0;
        $updated = 0;
        $skippedShort = 0;
        $skippedDuplicate = 0;
        $seenHashes = [];

        foreach ($reviews as $review) {
            $body = trim((string) $review['body']);
            if ($minLength > 0 && mb_strlen($body) < $minLength) {
                $skippedShort++;
                continue;
            }

            $rating = max(1, min(5, (int) $review['rating']));
            $author = trim((string) $review['author']) ?: 'Користувач';
            $date = $review['date'] ?? null;
            $hash = $this->buildReviewHash($author, $rating, $body, $date);

            // Сайт сам показує дублікати (той самий відгук двічі) — ріжемо в межах пачки.
            if (isset($seenHashes[$hash])) {
                $skippedDuplicate++;
                continue;
            }
            $seenHashes[$hash] = true;

            $existing = ProfileReview::query()
                ->where('profile_id', $profile->id)
                ->where('external_review_hash', $hash)
                ->first();

            if (! $existing) {
                // Той самий відгук міг зайти раніше з іншого джерела (Google/Top20)
                // або старим хешем — звіряємо автора, оцінку й текст.
                $existing = ProfileReview::query()
                    ->where('profile_id', $profile->id)
                    ->where('rating', $rating)
                    ->where('body', $body)
                    ->where(function ($query) use ($author): void {
                        $query->where('external_review_author', $author)
                            ->orWhere('author_name', $author);
                    })
                    ->first();
            }

            $publishedAt = $date ? CarbonImmutable::parse($date) : CarbonImmutable::now();

            $payload = [
                'author_name' => $author,
                'rating' => $rating,
                'title' => null,
                'body' => $body,
                'status' => $status,
                'verification_type' => self::VERIFICATION_TYPE,
                'published_at' => $publishedAt,
                'external_source_url' => $review['source_url'],
                'external_review_hash' => $hash,
                'external_source_type' => self::SOURCE_TYPE,
                'external_review_author' => $author,
                'external_review_date' => $date,
                'moderation_note' => 'Імпортовано з довідника uadvokat.com.ua; URL джерела збережено.',
            ];

            if ($existing) {
                $existing->fill($payload);
                $existing->saveQuietly();
                $updated++;
                continue;
            }

            $profile->reviews()->createQuietly($payload);
            $created++;
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped_short' => $skippedShort,
            'skipped_duplicate' => $skippedDuplicate,
        ];
    }

    public function buildReviewHash(string $author, int $rating, string $body, ?string $date): string
    {
        return sha1(implode('|', [
            self::SOURCE_TYPE,
            mb_strtolower(trim($author)),
            $rating,
            mb_strtolower(preg_replace('~\s+~u', ' ', trim($body)) ?? ''),
            (string) $date,
        ]));
    }

    /** «46 відгука» у блоці рейтингу — скільки відгуків обіцяє сайт. */
    private function extractDeclaredReviewCount(string $html): ?int
    {
        $value = $this->matchFirst('~itemprop="ratingCount"[^>]*>\s*(\d+)~', $html)
            ?? $this->matchFirst('~>\s*(\d+)\s*відгук~u', $html);

        return $value !== null ? (int) $value : null;
    }

    /** dd-mm-YYYY → Y-m-d */
    private function normalizeDate(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if (! preg_match('~^(\d{2})-(\d{2})-(\d{4})$~', $raw, $matches)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $matches[3] . '-' . $matches[2] . '-' . $matches[1]);
        } catch (\Throwable) {
            return null;
        }

        if (! $date || $date->isFuture()) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    /** «Адвокат Хайнацька Ганна Миколаївна» → «Хайнацька Ганна Миколаївна». */
    private function cleanLawyerName(string $raw): string
    {
        $name = $this->cleanText($raw);

        return trim(preg_replace('~^(Адвокат|Адвокатське об.єднання)\s+~ui', '', $name) ?? $name);
    }

    private function cleanText(string $raw): string
    {
        $text = preg_replace('~<br\s*/?>~i', "\n", $raw) ?? $raw;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('~[ \t]+~u', ' ', $text) ?? $text;
        $text = preg_replace('~\n{3,}~u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function matchFirst(string $pattern, string $subject): ?string
    {
        return preg_match($pattern, $subject, $matches) === 1 ? $matches[1] : null;
    }

    private function fetchHtml(string $url): ?string
    {
        try {
            $response = $this->http()->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $html = (string) $response->body();

        return $html !== '' ? $html : null;
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
            'Accept-Language' => 'uk-UA,uk;q=0.9',
        ])
            ->timeout(20)
            ->retry(2, 400, function (\Throwable $exception): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException
                    && $exception->response->serverError();
            }, throw: false);
    }
}
