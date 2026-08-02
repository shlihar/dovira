<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();

        $legalCategory = DB::table('categories')
            ->where('slug', 'catalog-yurydychni-poslugy')
            ->first();

        if (! $legalCategory) {
            return;
        }

        $advokaty = DB::table('categories')
            ->where('slug', 'advokaty')
            ->first();

        if ($advokaty) {
            DB::table('categories')
                ->where('id', $advokaty->id)
                ->update([
                    'parent_id' => $legalCategory->id,
                    'name' => 'Адвокати',
                    'status' => 'active',
                    'is_active' => true,
                    'show_in_catalog' => true,
                    'show_in_menu' => true,
                    'is_indexable' => true,
                    'pro_enabled' => true,
                    'updated_at' => $now,
                ]);
        } else {
            $advokatyId = DB::table('categories')->insertGetId([
                'parent_id' => $legalCategory->id,
                'name' => 'Адвокати',
                'slug' => 'advokaty',
                'description' => null,
                'status' => 'active',
                'is_active' => true,
                'sort_order' => 0,
                'show_on_homepage' => false,
                'show_in_menu' => true,
                'show_in_footer' => false,
                'show_in_catalog' => true,
                'is_indexable' => true,
                'pro_enabled' => true,
                'pro_price' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $advokaty = (object) ['id' => $advokatyId];
        }

        $notariusy = DB::table('categories')
            ->where('slug', 'notariusy')
            ->first();

        if ($notariusy) {
            DB::table('categories')
                ->where('id', $notariusy->id)
                ->update([
                    'parent_id' => $legalCategory->id,
                    'name' => 'Нотаріуси',
                    'status' => 'active',
                    'is_active' => true,
                    'show_in_catalog' => true,
                    'show_in_menu' => true,
                    'is_indexable' => true,
                    'pro_enabled' => true,
                    'updated_at' => $now,
                ]);
        } else {
            DB::table('categories')->insert([
                'parent_id' => $legalCategory->id,
                'name' => 'Нотаріуси',
                'slug' => 'notariusy',
                'description' => null,
                'status' => 'active',
                'is_active' => true,
                'sort_order' => 1,
                'show_on_homepage' => false,
                'show_in_menu' => true,
                'show_in_footer' => false,
                'show_in_catalog' => true,
                'is_indexable' => true,
                'pro_enabled' => true,
                'pro_price' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $profileIds = DB::table('profile_data_sources')
            ->where('source_type', 'top20_import')
            ->pluck('profile_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($profileIds === []) {
            return;
        }

        $eligibleProfileIds = DB::table('profile_category')
            ->whereIn('profile_id', $profileIds)
            ->where('category_id', $legalCategory->id)
            ->pluck('profile_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($eligibleProfileIds === []) {
            return;
        }

        $childCategoryIds = DB::table('categories')
            ->where('parent_id', $legalCategory->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $profilesWithChild = DB::table('profile_category')
            ->whereIn('profile_id', $eligibleProfileIds)
            ->whereIn('category_id', $childCategoryIds)
            ->pluck('profile_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $toBackfill = array_values(array_diff($eligibleProfileIds, $profilesWithChild));

        if ($toBackfill === []) {
            return;
        }

        DB::table('profile_category')
            ->whereIn('profile_id', $toBackfill)
            ->where('category_id', $legalCategory->id)
            ->update([
                'is_primary' => false,
                'updated_at' => $now,
            ]);

        $rows = array_map(fn (int $profileId) => [
            'profile_id' => $profileId,
            'category_id' => (int) $advokaty->id,
            'is_primary' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $toBackfill);

        DB::table('profile_category')->upsert(
            $rows,
            ['profile_id', 'category_id'],
            ['is_primary', 'updated_at']
        );
    }

    public function down(): void
    {
    }
};
