<?php

namespace App\Console\Commands;

use App\Models\Top20ImportItem;
use App\Support\CategoryHierarchy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairTop20ProfileCategories extends Command
{
    protected $signature = 'dovira:repair-top20-profile-categories
        {--profile-id= : Repair only one profile}
        {--limit=0 : Maximum Top20 import items to inspect, 0 means all}
        {--dry-run : Show what would change without writing}';

    protected $description = 'Attach all historical Top20 import categories/subcategories to imported profiles without detaching existing categories.';

    public function handle(): int
    {
        $profileId = filled($this->option('profile-id')) ? (int) $this->option('profile-id') : null;
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $query = Top20ImportItem::query()
            ->whereNotNull('profile_id')
            ->whereNotNull('category_id')
            ->orderBy('id');

        if ($profileId !== null) {
            $query->where('profile_id', $profileId);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $checked = 0;
        $inserted = 0;
        $updatedPrimary = 0;
        $skipped = 0;
        $plannedLinks = [];
        $rootIdCache = [];
        $childIdCache = [];
        $hasPrimaryCache = [];

        $query->chunkById(500, function ($items) use ($dryRun, &$checked, &$inserted, &$updatedPrimary, &$skipped, &$plannedLinks, &$rootIdCache, &$childIdCache, &$hasPrimaryCache): void {
            $targets = [];
            $profileIds = [];
            $categoryIds = [];

            foreach ($items as $item) {
                $checked++;

                $categoryId = (int) $item->category_id;
                $rootId = $rootIdCache[$categoryId] ??= CategoryHierarchy::resolveRootCategoryId($categoryId);
                if (! $rootId) {
                    $skipped++;
                    continue;
                }

                $subcategoryId = $item->subcategory_id ? (int) $item->subcategory_id : null;
                $childCacheKey = $rootId . ':' . ($subcategoryId ?: 0);
                $childId = $childIdCache[$childCacheKey] ??= CategoryHierarchy::resolveValidSubcategoryId($rootId, $subcategoryId);
                $profileId = (int) $item->profile_id;

                $rows = [
                    $rootId => false,
                ];

                if ($childId !== null) {
                    $rows[$childId] = true;
                } else {
                    $rows[$rootId] = true;
                }

                foreach ($rows as $categoryId => $isPrimary) {
                    $linkKey = $profileId . ':' . (int) $categoryId;
                    if (isset($plannedLinks[$linkKey])) {
                        continue;
                    }

                    $plannedLinks[$linkKey] = true;
                    $targets[$linkKey] = [
                        'profile_id' => $profileId,
                        'category_id' => (int) $categoryId,
                        'is_primary' => $isPrimary,
                    ];
                    $profileIds[$profileId] = $profileId;
                    $categoryIds[(int) $categoryId] = (int) $categoryId;
                }
            }

            if ($targets === []) {
                return;
            }

            $existingLinks = DB::table('profile_category')
                ->whereIn('profile_id', array_values($profileIds))
                ->whereIn('category_id', array_values($categoryIds))
                ->get(['id', 'profile_id', 'category_id', 'is_primary'])
                ->keyBy(fn ($row): string => ((int) $row->profile_id) . ':' . ((int) $row->category_id));

            $existingPrimaryProfileIds = DB::table('profile_category')
                ->whereIn('profile_id', array_values($profileIds))
                ->where('is_primary', true)
                ->pluck('profile_id')
                ->map(static fn (mixed $profileId): int => (int) $profileId)
                ->all();

            foreach ($existingPrimaryProfileIds as $profileId) {
                $hasPrimaryCache[$profileId] = true;
            }

            $now = now();
            $rowsToInsert = [];
            foreach ($targets as $linkKey => $target) {
                $profileId = (int) $target['profile_id'];
                $isPrimary = ! ($hasPrimaryCache[$profileId] ?? false) && (bool) $target['is_primary'];
                $existing = $existingLinks->get($linkKey);

                if ($existing) {
                    if ($isPrimary && ! (bool) $existing->is_primary) {
                        $updatedPrimary++;
                        $hasPrimaryCache[$profileId] = true;
                        if (! $dryRun) {
                            DB::table('profile_category')
                                ->where('id', $existing->id)
                                ->update([
                                    'is_primary' => true,
                                    'updated_at' => $now,
                                ]);
                        }
                    }

                    continue;
                }

                if ($isPrimary) {
                    $hasPrimaryCache[$profileId] = true;
                }

                $inserted++;
                $rowsToInsert[] = [
                    'profile_id' => $profileId,
                    'category_id' => (int) $target['category_id'],
                    'is_primary' => $isPrimary,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (! $dryRun && $rowsToInsert !== []) {
                DB::table('profile_category')->insertOrIgnore($rowsToInsert);
            }
        });

        $this->info('Top20 profile category repair completed.');
        $this->line('Checked import items: ' . $checked);
        $this->line('Inserted category links: ' . $inserted);
        $this->line('Updated primary links: ' . $updatedPrimary);
        $this->line('Skipped items: ' . $skipped);
        $this->line('Mode: ' . ($dryRun ? 'dry-run' : 'write'));

        return self::SUCCESS;
    }
}
