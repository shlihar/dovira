<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Models\ProfileReview;
use App\Services\ProfileReviewStatsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ImportTrustindexReviews extends Command
{
    protected $signature = 'dovira:import-trustindex
        {profile : ID профілю}
        {--domain= : Домен на Trustindex (за замовчуванням — з website профілю)}
        {--reviews-json= : Шлях до JSON з відгуками (обхід Cloudflare через WebFetch)}
        {--dry-run : Показати знайдені відгуки без збереження}';

    protected $description = 'Імпортує Google-відгуки профілю з Trustindex (JSON-LD) і публікує їх.';

    public function handle(ProfileReviewStatsService $stats): int
    {
        $profile = Profile::find((int) $this->argument('profile'));
        if (! $profile) {
            $this->error('Профіль не знайдено.');

            return self::FAILURE;
        }

        // Режим WebFetch: відгуки з готового JSON (обхід Cloudflare).
        if ($jsonPath = trim((string) $this->option('reviews-json'))) {
            if (! is_file($jsonPath)) {
                $this->error("JSON не знайдено: {$jsonPath}");

                return self::FAILURE;
            }
            $payload = json_decode((string) file_get_contents($jsonPath), true);
            $reviews = array_map(fn ($r) => [
                'author' => $r['author'] ?? null,
                'body' => $r['text'] ?? ($r['body'] ?? null),
                'rating' => $r['rating'] ?? null,
                'date' => $r['date'] ?? null,
                'url' => $r['url'] ?? null,
            ], is_array($payload['reviews'] ?? null) ? $payload['reviews'] : (is_array($payload) ? $payload : []));
            $data = ['count' => count($reviews), 'rating' => null];
        } else {
            $domain = trim((string) ($this->option('domain') ?: preg_replace('#^https?://(www\.)?#', '', rtrim((string) $profile->website, '/'))));
            if ($domain === '') {
                $this->error('Немає домену: задайте --domain або website профілю.');

                return self::FAILURE;
            }
            $domain = preg_replace('#/.*$#', '', $domain);

            $url = "https://www.trustindex.io/reviews/{$domain}";
            $this->info("Джерело: {$url}");

            $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])->timeout(20)->get($url);
            if (! $response->successful()) {
                $this->error('Не вдалося завантажити Trustindex ('.$response->status().').');

                return self::FAILURE;
            }

            $data = $this->extractReviewSchema($response->body());
            $reviews = $data['reviews'] ?? [];
        }

        if ($reviews === []) {
            $this->warn('Відгуків у JSON-LD не знайдено.');

            return self::SUCCESS;
        }

        $this->info('Знайдено відгуків: '.count($reviews).($data['count'] ? " (усього на Trustindex: {$data['count']}, рейтинг {$data['rating']})" : ''));

        $created = 0;
        $skipped = 0;

        foreach ($reviews as $r) {
            $author = trim((string) ($r['author'] ?? ''));
            $body = trim((string) ($r['body'] ?? ''));
            $rating = (int) round((float) ($r['rating'] ?? 0));
            if ($author === '' || $rating < 1) {
                continue;
            }

            $hash = hash('sha256', $profile->id.'|trustindex|'.$author.'|'.$body);

            if ($this->option('dry-run')) {
                $this->line("  {$rating}★ {$author}: ".Str::limit($body, 60));

                continue;
            }

            if (ProfileReview::where('external_review_hash', $hash)->exists()) {
                $skipped++;

                continue;
            }

            $date = $r['date'] ? Carbon::parse($r['date']) : now();

            ProfileReview::create([
                'profile_id' => $profile->id,
                'user_id' => null,
                'author_name' => $author,
                'rating' => $rating,
                'body' => $body,
                'status' => 'published',
                'published_at' => $date,
                'is_anonymous' => false,
                'verification_type' => 'external_google_import',
                'external_source_type' => 'google',
                'external_source_url' => $r['url'] ?? null,
                'external_review_author' => $author,
                'external_review_date' => $date,
                'external_review_hash' => $hash,
            ]);
            $created++;
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $stats->recalculateForProfileId((int) $profile->id);
        $profile->refresh();

        $this->info("Створено: {$created}, пропущено (дублі): {$skipped}. Відгуків у профілі: {$profile->reviews_count}, рейтинг: {$profile->rating_avg}.");

        return self::SUCCESS;
    }

    /**
     * @return array{reviews: array<int, array<string, mixed>>, count: int, rating: ?float}
     */
    private function extractReviewSchema(string $html): array
    {
        preg_match_all('#<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>#si', $html, $matches);

        foreach ($matches[1] ?? [] as $json) {
            $decoded = json_decode(trim($json), true);
            if (! is_array($decoded)) {
                continue;
            }
            $nodes = isset($decoded['@graph']) && is_array($decoded['@graph']) ? $decoded['@graph'] : [$decoded];

            foreach ($nodes as $node) {
                if (! is_array($node) || empty($node['review']) || ! is_array($node['review'])) {
                    continue;
                }

                $reviews = [];
                foreach ($node['review'] as $rev) {
                    if (! is_array($rev)) {
                        continue;
                    }
                    $reviews[] = [
                        'author' => data_get($rev, 'author.name'),
                        'body' => data_get($rev, 'reviewBody'),
                        'rating' => data_get($rev, 'reviewRating.ratingValue'),
                        'date' => data_get($rev, 'datePublished'),
                        'url' => data_get($rev, 'url'),
                    ];
                }

                return [
                    'reviews' => $reviews,
                    'count' => (int) data_get($node, 'aggregateRating.ratingCount', 0),
                    'rating' => data_get($node, 'aggregateRating.ratingValue'),
                ];
            }
        }

        return ['reviews' => [], 'count' => 0, 'rating' => null];
    }
}
