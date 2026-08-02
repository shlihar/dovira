<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $legalCategory = DB::table('categories')
            ->where('slug', 'catalog-yurydychni-poslugy')
            ->first();
        $lawyersCategory = DB::table('categories')
            ->where('slug', 'advokaty')
            ->first();

        if (! $legalCategory || ! $lawyersCategory) {
            return;
        }

        DB::table('categories')
            ->where('id', $lawyersCategory->id)
            ->update([
                'parent_id' => $legalCategory->id,
                'updated_at' => now(),
            ]);

        $profileIds = DB::table('profile_category')
            ->where('category_id', $lawyersCategory->id)
            ->pluck('profile_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($profileIds !== []) {
            DB::table('profile_category')
                ->where('category_id', $legalCategory->id)
                ->whereIn('profile_id', $profileIds)
                ->update([
                    'is_primary' => false,
                    'updated_at' => now(),
                ]);

            DB::table('profile_category')
                ->where('category_id', $lawyersCategory->id)
                ->whereIn('profile_id', $profileIds)
                ->update([
                    'is_primary' => true,
                    'updated_at' => now(),
                ]);

            $rootRows = collect($profileIds)
                ->map(fn (int $profileId) => [
                    'profile_id' => $profileId,
                    'category_id' => (int) $legalCategory->id,
                    'is_primary' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->all();

            DB::table('profile_category')->upsert(
                $rootRows,
                ['profile_id', 'category_id'],
                ['is_primary', 'updated_at']
            );
        }

        $sourceServices = DB::table('category_services')
            ->where('category_id', $lawyersCategory->id)
            ->orderBy('id')
            ->get();

        foreach ($sourceServices as $service) {
            $targetService = DB::table('category_services')
                ->where('category_id', $legalCategory->id)
                ->where('slug', $service->slug)
                ->first();

            if ($targetService) {
                $serviceProfileRows = DB::table('profile_category_service')
                    ->where('category_service_id', $service->id)
                    ->pluck('profile_id')
                    ->map(fn ($id) => [
                        'profile_id' => (int) $id,
                        'category_service_id' => (int) $targetService->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])
                    ->all();

                if ($serviceProfileRows !== []) {
                    DB::table('profile_category_service')->upsert(
                        $serviceProfileRows,
                        ['profile_id', 'category_service_id'],
                        ['updated_at']
                    );
                }

                DB::table('profile_category_service')
                    ->where('category_service_id', $service->id)
                    ->delete();

                DB::table('category_services')
                    ->where('id', $service->id)
                    ->delete();

                continue;
            }

            DB::table('category_services')
                ->where('id', $service->id)
                ->update([
                    'category_id' => $legalCategory->id,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        $lawyersCategory = DB::table('categories')
            ->where('slug', 'advokaty')
            ->first();

        if (! $lawyersCategory) {
            return;
        }

        DB::table('categories')
            ->where('id', $lawyersCategory->id)
            ->update([
                'parent_id' => null,
                'updated_at' => now(),
            ]);
    }
};
