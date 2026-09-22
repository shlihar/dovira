<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Reversible containment of explicitly reviewed identity conflicts, never heuristic bulk deletion. */
class QuarantineMixedProfiles extends Command
{
    protected $signature = 'dovira:quarantine-mixed-profiles {manifest} {--apply : Save a private backup and unpublish the reviewed records}';

    protected $description = 'Quarantine reviewed mixed-business profiles; read-only preview by default.';

    public function handle(): int
    {
        $manifest = json_decode(file_get_contents($this->argument('manifest')), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || $manifest === []) {
            throw new \RuntimeException('An explicit reviewed manifest is required.');
        }
        $ids = array_column($manifest, 'id');
        if (count(array_unique($ids)) !== count($manifest)) {
            throw new \RuntimeException('Duplicate profile IDs in manifest.');
        }
        $apply = (bool) $this->option('apply');
        $result = DB::transaction(function () use ($manifest, $ids, $apply): array {
            $profiles = DB::table('profiles')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $sources = DB::table('profile_data_sources')->whereIn('profile_id', $ids)->orderBy('id')->lockForUpdate()->get();
            foreach ($manifest as $expected) {
                $p = $profiles->firstWhere('id', $expected['id']);
                $actualSources = $sources->where('profile_id', $expected['id'])->pluck('title', 'id')->all();
                $expectedSources = $expected['sources'] ?? [];
                ksort($actualSources);
                ksort($expectedSources);
                if (! $p || $p->name !== $expected['name'] || $p->slug !== $expected['slug'] || $actualSources !== $expectedSources) {
                    throw new \RuntimeException('Identity/source mapping changed for #'.$expected['id'].'; review again.');
                }
                if ($p->owner_user_id || $p->is_owner_verified || $p->is_pro
                    || DB::table('profile_claims')->where('profile_id', $p->id)->where('status', 'approved')->exists()) {
                    throw new \RuntimeException('Owned/paid profile #'.$p->id.' requires individual repair.');
                }
            }
            $pending = $profiles->filter(fn ($p) => ! str_contains((string) $p->internal_note, '[IDENTITY_REPAIR_REVIEW]'));
            $result = ['mode' => $apply ? 'apply' : 'preview', 'reviewed' => count($ids), 'to_quarantine' => $pending->count(), 'ids' => $pending->pluck('id')->all()];
            if (! $apply || $pending->isEmpty()) {
                return $result;
            }

            $snapshot = ['captured_at' => now()->toIso8601String(), 'profiles' => $profiles, 'profile_data_sources' => $sources];
            foreach (['profile_reviews', 'profile_category', 'profile_category_service', 'top20_import_items', 'profile_ratings', 'profile_claims'] as $table) {
                $snapshot[$table] = DB::table($table)->whereIn('profile_id', $ids)->get();
            }
            $directory = storage_path('app/private/identity-repair');
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new \RuntimeException('Cannot create backup directory.');
            }
            $path = $directory.'/'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json.gz';
            $bytes = gzencode(json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $handle = fopen($path, 'x');
            if ($handle === false) {
                throw new \RuntimeException('Cannot create backup.');
            }
            chmod($path, 0600);
            try {
                if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle) || ! fsync($handle)) {
                    throw new \RuntimeException('Backup was not saved completely; no data changed.');
                }
            } finally {
                fclose($handle);
            }

            foreach ($pending as $p) {
                // Query builder deliberately avoids notification/enrichment observers.
                DB::table('profiles')->where('id', $p->id)->update([
                    'status' => 'draft', 'is_published' => false, 'show_in_catalog' => false,
                    'internal_note' => trim((string) $p->internal_note)."\n[IDENTITY_REPAIR_REVIEW] Conflicting businesses in import history; preserve all data until source-level restoration. Backup: ".basename($path),
                    'updated_at' => now(),
                ]);
            }
            $result['backup'] = $path;
            $result['backup_sha256'] = hash_file('sha256', $path);

            return $result;
        });
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        if ($apply && $result['to_quarantine'] > 0) {
            foreach (['public:catalog:stats:v1', 'public:catalog:category-filters:v1', 'public:catalog:category-options:v1', 'public:catalog:regions:v2'] as $key) {
                \Illuminate\Support\Facades\Cache::forget($key);
            }
            \App\Support\SitemapCache::forget();
            \App\Support\TopRatingIndexCache::forget();
            app(\App\Services\HomePageDataService::class)->refresh();
        }

        return self::SUCCESS;
    }
}
