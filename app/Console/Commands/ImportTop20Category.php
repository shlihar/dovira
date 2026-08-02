<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Services\Top20BulkImportService;
use Illuminate\Console\Command;

class ImportTop20Category extends Command
{
    protected $signature = 'dovira:import-top20-category
        {category : Config key from top20_bulk_import.categories}
        {city=od : Top20 city code, for example od}
        {--listing-url= : Override Top20 listing URL}
        {--start-page=1 : Start importing from this listing page}
        {--end-page= : Stop importing at this listing page}
        {--max-pages= : Limit listing pages to scan}
        {--max-profiles= : Limit imported profiles}
        {--min-reviews=1 : Import only companies with at least this many reviews}
        {--refresh-existing : Overwrite existing profile fields with Top20 data}
        {--publish-profiles : Publish newly created profiles immediately}
        {--dry-run : Scan and parse without DB writes}';

    protected $description = 'Bulk import Top20 category profiles and their reviews into DOVIRA.';

    public function handle(Top20BulkImportService $service): int
    {
        $categoryKey = (string) $this->argument('category');
        $cityCode = (string) $this->argument('city');
        $categoryConfig = config("top20_bulk_import.categories.{$categoryKey}");
        $cityConfig = config("top20_bulk_import.cities.{$cityCode}");

        if (! is_array($categoryConfig)) {
            $this->error("Unknown import category: {$categoryKey}");
            $this->line('Available keys: ' . implode(', ', array_keys((array) config('top20_bulk_import.categories', []))));

            return self::FAILURE;
        }

        if (! is_array($cityConfig)) {
            $this->error("Unknown Top20 city code: {$cityCode}");
            $this->line('Available city codes: ' . implode(', ', array_keys((array) config('top20_bulk_import.cities', []))));

            return self::FAILURE;
        }

        $listingUrl = trim((string) ($this->option('listing-url') ?: data_get($categoryConfig, "listing_urls.{$cityCode}", '')));
        if ($listingUrl === '') {
            $this->error("No Top20 listing URL configured for category [{$categoryKey}] and city [{$cityCode}].");
            $this->line('Pass `--listing-url=` explicitly or extend `config/top20_bulk_import.php`.');

            return self::FAILURE;
        }

        $category = Category::query()
            ->where('slug', (string) data_get($categoryConfig, 'category_slug'))
            ->first();

        if (! $category) {
            $category = Category::query()->create([
                'name' => (string) data_get($categoryConfig, 'label', $categoryKey),
                'slug' => (string) data_get($categoryConfig, 'category_slug'),
                'status' => 'active',
                'sort_order' => 2000,
                'show_in_catalog' => true,
                'show_in_menu' => true,
                'is_indexable' => true,
                'pro_enabled' => true,
            ]);
        }

        $this->line('Top20 bulk import');
        $this->line('Listing URL: ' . $listingUrl);
        $this->line('DOVIRA category: ' . $category->name . ' [' . $category->slug . ']');
        $this->line('City: ' . (string) data_get($cityConfig, 'name'));
        $this->line('Pages: ' . (filled($this->option('end-page'))
            ? ((int) $this->option('start-page')) . '-' . ((int) $this->option('end-page'))
            : ((int) $this->option('start-page')) . '+'));
        $this->line('Mode: ' . ((bool) $this->option('dry-run') ? 'dry-run' : 'write'));
        $this->newLine();

        $stats = $service->importCategory($listingUrl, $category, [
            'city_code' => $cityCode,
            'city_name' => (string) data_get($cityConfig, 'name', ''),
            'region_name' => (string) data_get($cityConfig, 'region', ''),
            'start_page' => $this->option('start-page'),
            'end_page' => $this->option('end-page'),
            'max_pages' => $this->option('max-pages'),
            'max_profiles' => $this->option('max-profiles'),
            'min_reviews' => $this->option('min-reviews'),
            'subcategory_slug' => (string) data_get($categoryConfig, 'subcategory_slug', ''),
            'refresh_existing' => (bool) $this->option('refresh-existing'),
            'publish_profiles' => (bool) $this->option('publish-profiles'),
            'dry_run' => (bool) $this->option('dry-run'),
        ]);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Pages visited', (string) ($stats['pages_visited'] ?? 0)],
                ['Cards found', (string) ($stats['cards_found'] ?? 0)],
                ['Profiles considered', (string) ($stats['profiles_considered'] ?? 0)],
                ['Profiles created', (string) ($stats['profiles_created'] ?? 0)],
                ['Profiles updated', (string) ($stats['profiles_updated'] ?? 0)],
                ['Profiles skipped', (string) ($stats['profiles_skipped'] ?? 0)],
                ['Reviews created', (string) ($stats['reviews_created'] ?? 0)],
                ['Reviews updated', (string) ($stats['reviews_updated'] ?? 0)],
                ['Reviews hidden', (string) ($stats['reviews_hidden'] ?? 0)],
                ['Errors', (string) count((array) ($stats['errors'] ?? []))],
            ]
        );

        $errors = array_slice((array) ($stats['errors'] ?? []), 0, 10);
        if ($errors !== []) {
            $this->newLine();
            $this->warn('First import errors:');
            foreach ($errors as $error) {
                if (! is_array($error)) {
                    continue;
                }

                $this->line('- ' . ($error['name'] ?? 'unknown') . ' | ' . ($error['url'] ?? '') . ' | ' . ($error['error'] ?? ''));
            }
        }

        return self::SUCCESS;
    }
}
