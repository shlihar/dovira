<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Завантажує аватарки авторів зовнішніх відгуків (сирі Google-URL) у локальний
 * сторедж і замінює URL на локальний шлях — durability + оптимізація + без
 * витоку IP користувачів на Google.
 *
 * Ідемпотентно: оновлює лише ті рядки, де аватар ще http(s); повторний запуск
 * підхоплює недокачане. Оновлення — прямим UPDATE (в обхід ProfileReviewObserver),
 * по одному запиту на унікальний URL (усі відгуки з ним отримують той самий файл).
 */
class LocalizeReviewAvatars extends Command
{
    protected $signature = 'dovira:localize-review-avatars
        {--all : Усі відгуки з сирим http-аватаром, а не лише Apify Google Maps}
        {--limit=0 : Обмежити кількість унікальних URL}
        {--dry-run : Порахувати без завантажень}';

    protected $description = 'Локалізує аватарки зовнішніх відгуків (Google-URL → локальний сторедж).';

    private const DELAY_MS = 90;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $query = DB::table('profile_reviews')
            ->where('external_review_author_avatar_url', 'like', 'http%');
        if (! $this->option('all')) {
            $query->where('moderation_note', 'like', '%Apify, Google Maps%');
        }

        $this->info('Збираю унікальні URL аватарок…');
        $urls = $query->select('external_review_author_avatar_url as u', DB::raw('MIN(author_name) as seed'))
            ->groupBy('external_review_author_avatar_url')
            ->get();
        $this->line('  унікальних URL: '.$urls->count());

        $done = 0;
        $failed = 0;
        $rowsUpdated = 0;

        foreach ($urls as $row) {
            if ($limit > 0 && $done >= $limit) {
                break;
            }

            $url = (string) $row->u;

            if ($dryRun) {
                $done++;
                continue;
            }

            $stored = $this->download($url, (string) ($row->seed ?: 'avatar'));
            if ($stored === null) {
                $failed++;
                continue;
            }

            // Усі відгуки з цим URL — на локальний файл (в обхід обсервера).
            $affected = DB::table('profile_reviews')
                ->where('external_review_author_avatar_url', $url)
                ->update(['external_review_author_avatar_url' => $stored]);
            $rowsUpdated += $affected;
            $done++;

            if ($done % 200 === 0) {
                $this->line("  … {$done} URL, оновлено рядків {$rowsUpdated}, помилок {$failed}");
            }

            usleep(self::DELAY_MS * 1000);
        }

        $this->newLine();
        $this->info($dryRun ? 'DRY-RUN.' : 'Готово.');
        $this->table(['Метрика', 'Значення'], [
            ['Унікальних URL', $urls->count()],
            [$dryRun ? 'Буде оброблено' : 'Завантажено URL', $done],
            ['Оновлено рядків-відгуків', $rowsUpdated],
            ['Помилок завантаження', $failed],
        ]);

        return self::SUCCESS;
    }

    private function download(string $url, string $seed): ?string
    {
        $absolute = str_starts_with($url, '//') ? 'https:'.$url : $url;
        if (! preg_match('#^https?://#i', $absolute)) {
            return null;
        }
        // Google-аватар без розмірної директиви віддає повнорозмірний файл
        // (кілька МБ) — просимо стандартні 160px, щоб не впертись у ліміт.
        if (str_contains($absolute, 'googleusercontent.com')) {
            $absolute = (string) preg_replace('/=[^\/=]*$/', '', $absolute).'=s160-c';
        }

        $disk = (string) config('top20_bulk_import.media_disk', 'public');
        $baseDir = trim((string) config('top20_bulk_import.media_directory', 'catalog-media'), '/');
        $maxBytes = (int) config('top20_bulk_import.review_avatar_max_bytes', 2 * 1024 * 1024);

        try {
            $response = Http::timeout((int) config('top20_bulk_import.media_timeout', 20))
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 DOVIRA media fetcher',
                    'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                ])
                ->retry(3, 500, function (\Throwable $e): bool {
                    return $e instanceof ConnectionException
                        || ($e instanceof RequestException && in_array($e->response->status(), [408, 425, 429, 500, 502, 503, 504], true));
                }, throw: false)
                ->get($absolute);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $content = $response->body();
        $len = strlen($content);
        if ($len < 100 || $len > max(1024, $maxBytes)) {
            return null;
        }

        $info = @getimagesizefromstring($content);
        if (! is_array($info)) {
            return null;
        }
        $extension = match (Str::lower((string) ($info['mime'] ?? ''))) {
            'image/jpeg', 'image/pjpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };
        if ($extension === null) {
            return null;
        }

        $hash = sha1($absolute);
        $slug = Str::slug(Str::ascii($seed)) ?: 'avatar';
        $path = trim($baseDir.'/review-avatars/'.substr($hash, 0, 2).'/'.$slug.'-'.substr($hash, 0, 20).'.'.$extension, '/');

        if (Storage::disk($disk)->exists($path)) {
            return $path;
        }

        return Storage::disk($disk)->put($path, $content) ? $path : null;
    }
}
