<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Profile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Застосовує точковий план виправлень каталогу: основна категорія, зайві та
 * відсутні категорії, місто й область. Рядок плану (JSONL):
 * {"profile_id": 1, "primary_category_id": 111, "detach_categories": [97],
 *  "attach_categories": [113], "city": "Трускавець", "region_id": 12, "note": "…"}
 *
 * Правки йдуть через модель Profile, щоб обсервер почистив публічні кеші.
 */
class FixCatalogMismatches extends Command
{
    protected $signature = 'dovira:fix-catalog-mismatches
        {file : Шлях до JSONL-файлу з планом правок}
        {--dry-run : Показати, що зміниться, без запису}';

    protected $description = 'Виправляє категорії та місто профілів за планом (JSONL).';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("Файл не знайдено: {$path}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $categories = Category::query()->pluck('name', 'id');

        $applied = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($this->readRows($path) as $index => $row) {
            $label = 'Рядок '.($index + 1);

            if (! is_array($row)) {
                $this->error("{$label}: не JSON — пропущено.");
                $errors++;

                continue;
            }

            $profileId = (int) ($row['profile_id'] ?? 0);
            $profile = $profileId > 0 ? Profile::query()->find($profileId) : null;

            if (! $profile) {
                $this->error("{$label}: профіль {$profileId} не знайдено.");
                $errors++;

                continue;
            }

            $changes = $this->planChanges($profile, $row, $categories);

            if ($changes === []) {
                $this->line("  #{$profileId} {$profile->name}: нічого змінювати.");
                $skipped++;

                continue;
            }

            $this->line("  #{$profileId} {$profile->name}");
            foreach ($changes as $change) {
                $this->line("      {$change}");
            }

            if ($dryRun) {
                $applied++;

                continue;
            }

            try {
                DB::transaction(function () use ($profile, $row): void {
                    $this->applyChanges($profile, $row);
                });
            } catch (Throwable $exception) {
                $this->error("  #{$profileId}: {$exception->getMessage()}");
                $errors++;

                continue;
            }

            $applied++;
        }

        $this->newLine();
        $this->info(($dryRun ? 'Буде змінено' : 'Змінено').": {$applied}, без змін: {$skipped}, помилок: {$errors}.");

        if (! $dryRun && $applied > 0) {
            $this->call('dovira:warm-home');
        }

        return $errors > 0 && $applied === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Людський опис того, що саме зміниться. Порожній масив — правити нічого.
     *
     * @param  array<string, mixed>  $row
     * @param  \Illuminate\Support\Collection<int, string>  $categories
     * @return array<int, string>
     */
    private function planChanges(Profile $profile, array $row, $categories): array
    {
        $changes = [];
        $current = $profile->categories()->pluck('categories.id')->all();
        $primary = (int) ($profile->categories()->wherePivot('is_primary', true)->value('categories.id') ?? 0);

        foreach ($this->ids($row, 'attach_categories') as $id) {
            if (! in_array($id, $current, true)) {
                $changes[] = "+ категорія {$id}:".($categories[$id] ?? '?');
            }
        }

        $newPrimary = (int) ($row['primary_category_id'] ?? 0);
        if ($newPrimary > 0 && $newPrimary !== $primary) {
            $changes[] = 'основна категорія: '
                .($primary > 0 ? $primary.':'.($categories[$primary] ?? '?') : '—')
                .' → '.$newPrimary.':'.($categories[$newPrimary] ?? '?');
        }

        foreach ($this->ids($row, 'detach_categories') as $id) {
            if (in_array($id, $current, true)) {
                $changes[] = "− категорія {$id}:".($categories[$id] ?? '?');
            }
        }

        $city = trim((string) ($row['city'] ?? ''));
        if ($city !== '' && $city !== (string) $profile->city) {
            $changes[] = "місто: {$profile->city} → {$city}";
        }

        $regionId = (int) ($row['region_id'] ?? 0);
        if ($regionId > 0 && $regionId !== (int) $profile->region_id) {
            $changes[] = "область: {$profile->region_id} → {$regionId}";
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function applyChanges(Profile $profile, array $row): void
    {
        foreach ($this->ids($row, 'attach_categories') as $id) {
            $profile->categories()->syncWithoutDetaching([$id => ['is_primary' => false]]);
        }

        $newPrimary = (int) ($row['primary_category_id'] ?? 0);
        if ($newPrimary > 0) {
            $profile->categories()->syncWithoutDetaching([$newPrimary => ['is_primary' => false]]);
            $profile->categories()->newPivotQuery()->update(['is_primary' => false]);
            $profile->categories()->updateExistingPivot($newPrimary, ['is_primary' => true]);
        }

        $detach = array_values(array_diff($this->ids($row, 'detach_categories'), [$newPrimary]));
        if ($detach !== []) {
            $profile->categories()->detach($detach);
        }

        $attributes = [];

        $city = trim((string) ($row['city'] ?? ''));
        if ($city !== '') {
            $attributes['city'] = $city;
        }

        $regionId = (int) ($row['region_id'] ?? 0);
        if ($regionId > 0) {
            $attributes['region_id'] = $regionId;
        }

        if ($attributes !== []) {
            $profile->forceFill($attributes)->save();
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, int>
     */
    private function ids(array $row, string $key): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', (array) ($row[$key] ?? []))
        )));
    }

    /**
     * @return array<int, mixed>
     */
    private function readRows(string $path): array
    {
        $contents = trim((string) file_get_contents($path));
        if ($contents === '') {
            return [];
        }

        if (str_starts_with($contents, '[')) {
            $decoded = json_decode($contents, true);

            return is_array($decoded) ? array_values($decoded) : [];
        }

        $rows = [];
        // Не \R: без модифікатора /u він матчить байт \x85, який зустрічається
        // всередині UTF-8 кирилиці і рве рядок посеред слова.
        foreach (preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $rows[] = json_decode($line, true);
        }

        return $rows;
    }
}
