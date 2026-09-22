<?php

namespace App\Console\Commands;

use App\Support\ProfileSearchTerms;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Перебудова пошукового індексу профілів (profile_search_terms).
 *
 * Збираємо в одне поле: назву, місто, район, категорії, послуги і найчастіші
 * значущі слова з опублікованих відгуків. Далі каталог шукає по цьому полю
 * через FULLTEXT — так запит «вакцинація тварин» знаходить клініки, у чиїх
 * відгуках про це пишуть, навіть якщо такої послуги немає в довіднику.
 */
class RebuildProfileSearchIndex extends Command
{
    protected $signature = 'dovira:search:rebuild
        {--profile= : Тільки один профіль (id)}
        {--reviews=150 : Скільки відгуків на профіль брати}
        {--chunk=500 : Розмір пачки профілів}';

    protected $description = 'Перебудовує пошуковий індекс профілів для запитів за потребою';

    public function handle(): int
    {
        $started = microtime(true);
        $reviewsPerProfile = max(10, (int) $this->option('reviews'));
        $chunk = max(50, (int) $this->option('chunk'));
        $onlyProfile = $this->option('profile') !== null ? (int) $this->option('profile') : null;

        $base = DB::table('profiles')
            ->where('status', 'active')
            ->where('is_published', true)
            ->where('show_in_catalog', true)
            ->when($onlyProfile !== null, fn ($q) => $q->where('id', $onlyProfile));

        $total = (clone $base)->count();
        $this->info("Профілів до індексації: {$total}");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $done = 0;
        $lastId = 0;
        while (true) {
            $ids = (clone $base)->where('id', '>', $lastId)->orderBy('id')->limit($chunk)->pluck('id')->all();
            if ($ids === []) {
                break;
            }
            $lastId = (int) end($ids);

            $rows = $this->buildRows($ids, $reviewsPerProfile);
            if ($rows !== []) {
                DB::table('profile_search_terms')->upsert($rows, ['profile_id'], ['terms', 'reviews_used', 'updated_at']);
            }

            $done += count($ids);
            $bar->setProgress(min($done, $total));
            if ($onlyProfile !== null) {
                break;
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info(sprintf('Готово за %.0f с. Рядків в індексі: %d', microtime(true) - $started, DB::table('profile_search_terms')->count()));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(array $ids, int $reviewsPerProfile): array
    {
        $profiles = DB::table('profiles')
            ->whereIn('id', $ids)
            ->select(['id', 'name', 'city', 'district', 'address'])
            ->get()
            ->keyBy('id');

        $categories = DB::table('profile_category')
            ->join('categories', 'categories.id', '=', 'profile_category.category_id')
            ->whereIn('profile_category.profile_id', $ids)
            ->select(['profile_category.profile_id', 'categories.name'])
            ->get()
            ->groupBy('profile_id');

        $services = DB::table('profile_category_service')
            ->join('category_services', 'category_services.id', '=', 'profile_category_service.category_service_id')
            ->whereIn('profile_category_service.profile_id', $ids)
            ->select(['profile_category_service.profile_id', 'category_services.name'])
            ->get()
            ->groupBy('profile_id');

        // Тіла відгуків ріжемо в SQL: для ключових слів повний текст не потрібен,
        // а передавати сотні мегабайт із віддаленої БД немає сенсу.
        $reviews = DB::table('profile_reviews')
            ->whereIn('profile_id', $ids)
            ->where('status', 'published')
            ->selectRaw('profile_id, SUBSTR(body, 1, 500) AS body')
            ->orderByDesc('id')
            ->get()
            ->groupBy('profile_id');

        $now = now();
        $rows = [];
        foreach ($ids as $id) {
            $profile = $profiles[$id] ?? null;
            if (! $profile) {
                continue;
            }

            $base = [
                (string) $profile->name,
                (string) $profile->city,
                (string) $profile->district,
                (string) $profile->address,
            ];
            foreach (($categories[$id] ?? collect()) as $row) {
                $base[] = (string) $row->name;
            }
            foreach (($services[$id] ?? collect()) as $row) {
                $base[] = (string) $row->name;
            }

            $profileReviews = ($reviews[$id] ?? collect())->take($reviewsPerProfile);
            $keywords = ProfileSearchTerms::keywords($profileReviews->pluck('body')->implode(' '));

            $terms = ProfileSearchTerms::normalize(implode(' ', $base)) . ' ' . implode(' ', $keywords);

            $rows[] = [
                'profile_id' => $id,
                'terms' => trim($terms),
                'reviews_used' => $profileReviews->count(),
                'updated_at' => $now,
            ];
        }

        return $rows;
    }
}
