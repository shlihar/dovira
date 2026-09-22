<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Models\ProfileDataSource;
use App\Services\ProfileReviewStatsService;
use App\Services\UadvokatReviewImportService;
use Illuminate\Console\Command;

class ImportUadvokatReviews extends Command
{
    protected $signature = 'dovira:import-uadvokat-reviews
        {--source=feed : feed — обхід стрічки /reviews.html (швидко, всі відгуки сайту); profiles — обхід карток наших профілів}
        {--feed-start=0 : З якої сторінки стрічки починати (0 = /reviews.html)}
        {--feed-pages= : Скільки сторінок стрічки пройти; порожньо = до кінця}
        {--profile-id=* : Режим profiles: обмежити конкретними профілями}
        {--limit= : Режим profiles: скільки профілів обробити}
        {--offset=0 : Режим profiles: пропустити перші N профілів}
        {--max-pages=30 : Режим profiles: максимум сторінок відгуків на адвоката}
        {--min-length=0 : Пропускати відгуки, коротші за N символів}
        {--status=published : Статус імпортованих відгуків: published|pending}
        {--skip-scraped : Режим profiles: не чіпати профілі, де відгуки з uadvokat вже є}
        {--unmatched-out= : Куди вивантажити CSV з адвокатами, у яких є відгуки, але немає профілю в базі}
        {--dry-run : Лише показати, що знайдено, без запису в базу}';

    protected $description = 'Імпорт відгуків про адвокатів з uadvokat.com.ua у профілі DOVIRA.';

    public function handle(UadvokatReviewImportService $service, ProfileReviewStatsService $stats): int
    {
        $status = (string) $this->option('status');
        if (! in_array($status, ['published', 'pending'], true)) {
            $this->error('Невідомий --status. Доступні: published, pending.');

            return self::FAILURE;
        }

        return match ((string) $this->option('source')) {
            'feed' => $this->runFeed($service, $stats, $status),
            'profiles' => $this->runProfiles($service, $stats, $status),
            default => tap(self::FAILURE, fn () => $this->error('Невідомий --source. Доступні: feed, profiles.')),
        };
    }

    /**
     * Обхід глобальної стрічки відгуків: один запит на 10 відгуків одразу з
     * посиланням на адвоката. Покриває весь сайт, а не лише наші профілі.
     */
    private function runFeed(UadvokatReviewImportService $service, ProfileReviewStatsService $stats, string $status): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $minLength = max(0, (int) $this->option('min-length'));
        $startPage = max(0, (int) $this->option('feed-start'));
        $maxPages = filled($this->option('feed-pages')) ? max(1, (int) $this->option('feed-pages')) : null;

        $urlToProfileId = $this->buildLawyerUrlIndex($service);
        $this->line(sprintf(
            'У базі %d профілів з карткою uadvokat. Обходжу стрічку відгуків зі сторінки %d%s%s.',
            count($urlToProfileId),
            $startPage,
            $maxPages !== null ? sprintf(' (%d сторінок)', $maxPages) : ' до кінця',
            $dryRun ? ', dry-run' : ''
        ));

        /** @var array<string, array<int, array<string, mixed>>> $byLawyer */
        $byLawyer = [];
        $pagesVisited = 0;
        $reviewsFound = 0;
        $unmatched = [];

        for ($page = $startPage; $maxPages === null || $pagesVisited < $maxPages; $page++) {
            $reviews = $service->fetchFeedPage($page);
            if ($reviews === null) {
                break;
            }

            $pagesVisited++;
            if ($reviews === []) {
                break;
            }

            foreach ($reviews as $review) {
                $reviewsFound++;
                $lawyerUrl = (string) $review['lawyer_url'];

                if (! isset($urlToProfileId[$lawyerUrl])) {
                    $unmatched[$lawyerUrl] ??= ['name' => (string) $review['lawyer_name'], 'count' => 0];
                    $unmatched[$lawyerUrl]['count']++;
                    continue;
                }

                $byLawyer[$lawyerUrl][] = $review;
            }

            if ($pagesVisited % 25 === 0) {
                $this->line(sprintf(
                    '  …сторінок %d, відгуків %d, з них наших адвокатів %d',
                    $pagesVisited,
                    $reviewsFound,
                    array_sum(array_map('count', $byLawyer))
                ));
            }

            usleep($service->requestDelayMs() * 1000);
        }

        $matchedReviews = array_sum(array_map('count', $byLawyer));
        $totals = ['created' => 0, 'updated' => 0, 'skipped_short' => 0, 'skipped_duplicate' => 0, 'profiles' => 0];

        if (! $dryRun && $byLawyer !== []) {
            $bar = $this->output->createProgressBar(count($byLawyer));
            $bar->start();

            foreach ($byLawyer as $lawyerUrl => $reviews) {
                $bar->advance();
                $profile = Profile::query()->find($urlToProfileId[$lawyerUrl]);
                if (! $profile) {
                    continue;
                }

                $stored = $service->storeReviews($profile, $reviews, $status, $minLength);
                $totals['created'] += $stored['created'];
                $totals['updated'] += $stored['updated'];
                $totals['skipped_short'] += $stored['skipped_short'];
                $totals['skipped_duplicate'] += $stored['skipped_duplicate'];
                $totals['profiles']++;

                if ($stored['created'] > 0 || $stored['updated'] > 0) {
                    $stats->recalculateForProfileId((int) $profile->id);
                }
            }

            $bar->finish();
            $this->newLine(2);
        }

        uasort($unmatched, fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $unmatchedPath = $this->dumpUnmatched($unmatched);

        $this->table(['Метрика', 'Значення'], [
            ['Сторінок стрічки пройдено', (string) $pagesVisited],
            ['Відгуків у стрічці', (string) $reviewsFound],
            ['Відгуків для наших профілів', (string) $matchedReviews],
            ['Адвокатів (наших) з відгуками', (string) count($byLawyer)],
            ['Адвокатів поза нашою базою', (string) count($unmatched)],
            ['Відгуків у них', (string) array_sum(array_column($unmatched, 'count'))],
            ['Створено в базі', $dryRun ? '— (dry-run)' : (string) $totals['created']],
            ['Оновлено наявних', $dryRun ? '—' : (string) $totals['updated']],
            ['Пропущено (закороткі)', $dryRun ? '—' : (string) $totals['skipped_short']],
            ['Пропущено (дублі на сайті)', $dryRun ? '—' : (string) $totals['skipped_duplicate']],
        ]);

        if ($unmatched !== []) {
            $this->newLine();
            $this->line('Найчастіші адвокати з відгуками, яких немає в базі (їх можна дотягнути через dovira:import-uadvokat):');
            foreach (array_slice($unmatched, 0, 10, true) as $url => $row) {
                $this->line(sprintf('  %3d × %s — %s', $row['count'], $row['name'] ?: '?', $url));
            }

            if ($unmatchedPath !== null) {
                $this->newLine();
                $this->info('Повний список: ' . $unmatchedPath);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array{name:string, count:int}>  $unmatched
     */
    private function dumpUnmatched(array $unmatched): ?string
    {
        $path = (string) $this->option('unmatched-out');
        if ($path === '' || $unmatched === []) {
            return null;
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            $this->warn('Не вдалося записати ' . $path);

            return null;
        }

        fputcsv($handle, ['lawyer_name', 'lawyer_url', 'reviews_count']);
        foreach ($unmatched as $url => $row) {
            fputcsv($handle, [$row['name'], $url, $row['count']]);
        }
        fclose($handle);

        return $path;
    }

    /**
     * Обхід сторінок відгуків наших профілів по одному. Повільніше за стрічку,
     * але корисно для точкового оновлення конкретного адвоката.
     */
    private function runProfiles(UadvokatReviewImportService $service, ProfileReviewStatsService $stats, string $status): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $maxPages = max(1, (int) $this->option('max-pages'));
        $minLength = max(0, (int) $this->option('min-length'));

        $sources = $this->resolveSources();
        if ($sources === []) {
            $this->error('Не знайдено профілів із збереженим URL картки uadvokat.com.ua.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Профілів до обходу: %d%s. Пауза %d мс між запитами.',
            count($sources),
            $dryRun ? ' (dry-run)' : '',
            $service->requestDelayMs()
        ));

        $totals = [
            'profiles_with_reviews' => 0,
            'profiles_without_reviews' => 0,
            'reviews_found' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped_short' => 0,
            'skipped_duplicate' => 0,
        ];

        $bar = $this->output->createProgressBar(count($sources));
        $bar->start();

        foreach ($sources as $source) {
            $bar->advance();

            $profile = Profile::query()->find($source->profile_id);
            if (! $profile) {
                continue;
            }

            $result = $service->fetchLawyerReviews((string) $source->url, $maxPages);
            usleep($service->requestDelayMs() * 1000);

            if ($result['reviews'] === []) {
                $totals['profiles_without_reviews']++;
                continue;
            }

            $totals['profiles_with_reviews']++;
            $totals['reviews_found'] += count($result['reviews']);

            if ($dryRun) {
                continue;
            }

            $stored = $service->storeReviews($profile, $result['reviews'], $status, $minLength);
            $totals['created'] += $stored['created'];
            $totals['updated'] += $stored['updated'];
            $totals['skipped_short'] += $stored['skipped_short'];
            $totals['skipped_duplicate'] += $stored['skipped_duplicate'];

            if ($stored['created'] > 0 || $stored['updated'] > 0) {
                $stats->recalculateForProfileId((int) $profile->id);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(['Метрика', 'Значення'], [
            ['Профілів із відгуками', (string) $totals['profiles_with_reviews']],
            ['Профілів без відгуків (404)', (string) $totals['profiles_without_reviews']],
            ['Відгуків знайдено на сайті', (string) $totals['reviews_found']],
            ['Створено в базі', $dryRun ? '— (dry-run)' : (string) $totals['created']],
            ['Оновлено наявних', $dryRun ? '—' : (string) $totals['updated']],
            ['Пропущено (закороткі)', $dryRun ? '—' : (string) $totals['skipped_short']],
            ['Пропущено (дублі на сайті)', $dryRun ? '—' : (string) $totals['skipped_duplicate']],
        ]);

        return self::SUCCESS;
    }

    /**
     * @return array<string, int> нормалізований URL картки → profile_id
     */
    private function buildLawyerUrlIndex(UadvokatReviewImportService $service): array
    {
        $index = [];

        ProfileDataSource::query()
            ->where('source_type', 'uadvokat_import')
            ->whereNotNull('url')
            ->orderBy('profile_id')
            ->chunkById(1000, function ($sources) use (&$index, $service): void {
                foreach ($sources as $source) {
                    $url = $service->normalizeLawyerUrl((string) $source->url);
                    if ($url !== '' && ! isset($index[$url])) {
                        $index[$url] = (int) $source->profile_id;
                    }
                }
            });

        return $index;
    }

    /**
     * @return array<int, ProfileDataSource>
     */
    private function resolveSources(): array
    {
        $profileIds = array_values(array_filter(array_map('intval', (array) $this->option('profile-id'))));

        $query = ProfileDataSource::query()
            ->where('source_type', 'uadvokat_import')
            ->whereNotNull('url')
            ->when($profileIds !== [], fn ($builder) => $builder->whereIn('profile_id', $profileIds))
            ->when((bool) $this->option('skip-scraped'), function ($builder): void {
                $builder->whereNotExists(function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('profile_reviews')
                        ->whereColumn('profile_reviews.profile_id', 'profile_data_sources.profile_id')
                        ->where('profile_reviews.external_source_type', UadvokatReviewImportService::SOURCE_TYPE);
                });
            })
            ->orderBy('profile_id');

        $offset = max(0, (int) $this->option('offset'));
        if ($offset > 0) {
            $query->skip($offset);
        }

        if (filled($this->option('limit'))) {
            $query->take(max(1, (int) $this->option('limit')));
        } elseif ($offset > 0) {
            $query->take(PHP_INT_MAX);
        }

        return $query->get()->unique('profile_id')->values()->all();
    }
}
