<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Models\ProfileDataSource;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Дозаливка лого/фото профілів з поля imageUrl датасету Apify Google Maps.
 *
 * Профілі вже створені командою dovira:import-google-maps; шукаємо їх за placeId
 * (profile_data_sources.found_fields.place_id) і, якщо лого порожнє, згенероване
 * чи ефемерний імпорт-файл (під catalog-media/), проставляємо ЗОВНІШНІЙ Google-URL
 * прямо в logo_url. Так лого не залежить від локального сховища (диск Render
 * ефемерний) і переживає деплой. Власні лого з кабінету (profiles/...) та вже
 * проставлені зовнішні URL не чіпаємо. Старий режим із завантаженням файлу —
 * під прапорцем --download.
 */
class ImportGoogleMapsLogos extends Command
{
    protected $signature = 'dovira:import-google-logos
        {path : JSON-файл Apify або тека з файлами}
        {--limit=0 : Обмежити кількість профілів}
        {--overwrite : Замінювати навіть власні лого (profiles/...) та зовнішні URL}
        {--download : Старий режим: завантажувати файл у локальний сторедж замість зовнішнього URL}
        {--dry-run : Порахувати без запису}';

    protected $description = 'Проставляє профілям зовнішнє Google-лого з imageUrl датасету Google Maps (Apify).';

    private const DELAY_MS = 120;

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $files = is_dir($path)
            ? glob(rtrim($path, '/').'/*.json') ?: []
            : (is_file($path) ? [$path] : []);
        if ($files === []) {
            $this->error("Не знайдено JSON: {$path}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $overwrite = (bool) $this->option('overwrite');
        $download = (bool) $this->option('download');
        $limit = (int) $this->option('limit');

        $this->info('Будую мапу placeId → профіль…');
        $placeMap = $this->buildPlaceMap();
        $this->line('  відомих placeId: '.count($placeMap));

        $counters = ['seen' => 0, 'no_profile' => 0, 'has_logo' => 0, 'no_image' => 0, 'street_view' => 0, 'linked' => 0, 'failed' => 0];
        $seenPlace = [];

        foreach ($files as $file) {
            $items = json_decode((string) file_get_contents($file), true);
            if (! is_array($items)) {
                $this->warn('  пропуск (не JSON): '.basename($file));
                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                if ($limit > 0 && $counters['linked'] >= $limit) {
                    break 2;
                }

                $placeId = trim((string) ($item['placeId'] ?? ''));
                $imageUrl = trim((string) ($item['imageUrl'] ?? ''));
                if ($placeId === '' || isset($seenPlace[$placeId])) {
                    continue;
                }
                $seenPlace[$placeId] = true;

                $profileId = $placeMap[$placeId] ?? null;
                if ($profileId === null) {
                    $counters['no_profile']++;
                    continue;
                }
                if ($imageUrl === '') {
                    $counters['no_image']++;
                    continue;
                }
                // Street View (фото будівлі/вулиці) — не лого й не портрет, пропускаємо.
                if (! str_contains($imageUrl, 'googleusercontent.com')) {
                    $counters['street_view']++;
                    continue;
                }

                $counters['seen']++;

                $profile = Profile::find($profileId);
                if ($profile === null) {
                    $counters['no_profile']++;
                    continue;
                }

                if (! $this->shouldReplace((string) ($profile->logo_url ?? ''), $overwrite)) {
                    $counters['has_logo']++;
                    continue;
                }

                if ($dryRun) {
                    $counters['linked']++;
                    if ($counters['linked'] <= 15) {
                        $this->line(sprintf('  [%s] #%d %s ← %s', $profile->city ?: '—', $profile->id, mb_substr((string) $profile->name, 0, 34), mb_substr($imageUrl, 0, 50)));
                    }
                    continue;
                }

                if ($download) {
                    $stored = $this->download($imageUrl, (string) $profile->name);
                    if ($stored === null) {
                        $counters['failed']++;
                        continue;
                    }
                    $profile->logo_url = $stored;
                } else {
                    // Зовнішній Google-URL прямо в logo_url — без локального файлу.
                    $profile->logo_url = $this->withSize($imageUrl);
                }

                $profile->saveQuietly();
                $counters['linked']++;

                if ($counters['linked'] % 50 === 0) {
                    $this->line('  … проставлено '.$counters['linked']);
                }

                if ($download) {
                    usleep(self::DELAY_MS * 1000);
                }
            }
        }

        $this->newLine();
        $this->info($dryRun ? 'DRY-RUN.' : 'Готово.');
        $this->table(['Метрика', 'Значення'], [
            ['Місць з профілем і imageUrl', $counters['seen']],
            ['Немає профілю (placeId не знайдено)', $counters['no_profile']],
            ['Немає imageUrl', $counters['no_image']],
            ['Пропущено Street View (не лого)', $counters['street_view']],
            ['Пропущено (власне лого / вже зовнішній URL)', $counters['has_logo']],
            [$dryRun ? 'Буде проставлено' : ($download ? 'Завантажено й проставлено' : 'Проставлено зовнішній URL'), $counters['linked']],
            ['Помилок завантаження', $counters['failed']],
        ]);

        return self::SUCCESS;
    }

    /**
     * Чи треба замінити поточне лого зовнішнім Google-URL.
     *
     * Не чіпаємо: власні завантаження з кабінету (profiles/…) та вже проставлені
     * зовнішні URL. Замінюємо: порожнє, згенеровані фолбеки й будь-які імпорт-файли
     * під catalog-media/ (вони ефемерні на проді або є плейсхолдерами).
     */
    private function shouldReplace(string $current, bool $overwrite): bool
    {
        if ($overwrite) {
            return true;
        }

        $current = trim($current);
        if ($current === '') {
            return true;
        }

        if (Str::startsWith($current, ['http://', 'https://'])) {
            return false;
        }

        $normalized = ltrim((string) preg_replace('#^(storage|media)/#', '', $current), '/');

        // Завантаження власника через PRO-кабінет (profiles/{id}/logo, profiles/logos/…).
        if (Str::startsWith($normalized, 'profiles/')) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, int>
     */
    private function buildPlaceMap(): array
    {
        $map = [];
        ProfileDataSource::query()
            ->where('source_type', 'google_maps_import')
            ->orderBy('id')
            ->chunk(2000, function ($rows) use (&$map): void {
                foreach ($rows as $row) {
                    $found = is_array($row->found_fields)
                        ? $row->found_fields
                        : (array) json_decode((string) $row->found_fields, true);
                    $placeId = trim((string) ($found['place_id'] ?? ''));
                    if ($placeId !== '') {
                        $map[$placeId] = (int) $row->profile_id;
                    }
                }
            });

        return $map;
    }

    private function download(string $url, string $seed): ?string
    {
        $url = $this->withSize($url);
        $disk = (string) config('top20_bulk_import.media_disk', 'public');
        $baseDir = trim((string) config('top20_bulk_import.media_directory', 'catalog-media'), '/');
        $maxBytes = (int) config('top20_bulk_import.media_max_bytes', 6 * 1024 * 1024);

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
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $content = $response->body();
        $len = strlen($content);
        if ($len < 128 || $len > max(1024, $maxBytes)) {
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

        $hash = sha1($url);
        $slug = Str::slug(Str::ascii($seed)) ?: 'logo';
        $storedPath = trim($baseDir.'/profile-logos/'.substr($hash, 0, 2).'/'.$slug.'-'.substr($hash, 0, 20).'.'.$extension, '/');

        if (Storage::disk($disk)->exists($storedPath)) {
            return $storedPath;
        }

        return Storage::disk($disk)->put($storedPath, $content) ? $storedPath : null;
    }

    /**
     * Google-URL без розмірної директиви віддає дрібну прев'юшку — просимо ~800px.
     */
    private function withSize(string $url): string
    {
        if (! str_contains($url, 'googleusercontent.com')) {
            return $url;
        }
        if (preg_match('/=[swh]\d/', $url)) {
            return $url;
        }

        return $url.'=s800';
    }
}
