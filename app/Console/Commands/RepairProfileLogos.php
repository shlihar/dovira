<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Services\Top20BulkImportService;
use App\Support\GeneratedProfileLogo;
use App\Support\MediaUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;

class RepairProfileLogos extends Command
{
    protected $signature = 'dovira:repair-profile-logos
        {--profile-id= : Repair only one profile by ID}
        {--slug= : Repair only one profile by slug}
        {--min-id= : Process only profiles with ID greater than or equal to this value}
        {--max-id= : Process only profiles with ID less than or equal to this value}
        {--limit=100 : Max profiles to process in one run}
        {--all : Process all Top20-linked profiles, not only problematic ones}
        {--include-all-profiles : Include profiles without Top20 sources}
        {--generate-fallback : Generate a local fallback logo when no real logo can be resolved}
        {--latest : Process newest profiles first}
        {--duplicate-threshold=3 : Mark logos repeated across this many profiles as suspicious}
        {--no-fetch-top20 : Do not fetch profile details from Top20 during repair}
        {--dry-run : Show what would change without saving}';

    protected $description = 'Refresh Top20 profile logos without running a full import.';

    public function handle(Top20BulkImportService $service): int
    {
        $profileId = $this->option('profile-id');
        $slug = trim((string) $this->option('slug'));
        $minId = $this->option('min-id');
        $maxId = $this->option('max-id');
        $limit = max(1, (int) $this->option('limit'));
        $processAll = (bool) $this->option('all');
        $includeAllProfiles = (bool) $this->option('include-all-profiles');
        $generateFallback = (bool) $this->option('generate-fallback');
        $latestFirst = (bool) $this->option('latest');
        $duplicateThreshold = max(2, (int) $this->option('duplicate-threshold'));
        $fetchTop20 = ! (bool) $this->option('no-fetch-top20');
        $dryRun = (bool) $this->option('dry-run');

        $duplicateLogoCounts = DB::table('profiles')
            ->select('logo_url', DB::raw('COUNT(*) as aggregate_count'))
            ->whereNotNull('logo_url')
            ->where('logo_url', '<>', '')
            ->groupBy('logo_url')
            ->having('aggregate_count', '>=', $duplicateThreshold)
            ->pluck('aggregate_count', 'logo_url')
            ->map(static fn ($count): int => (int) $count)
            ->all();

        $query = Profile::query()->with('dataSources');

        if (! $includeAllProfiles) {
            $query->whereHas('dataSources', fn ($builder) => $builder->whereIn('source_type', ['top20_import', 'top20']));
        }

        $latestFirst
            ? $query->orderByDesc('id')
            : $query->orderBy('id');

        if (filled($profileId)) {
            $query->whereKey((int) $profileId);
        }

        if ($slug !== '') {
            $query->where('slug', $slug);
        }

        if (filled($minId)) {
            $query->where('id', '>=', (int) $minId);
        }

        if (filled($maxId)) {
            $query->where('id', '<=', (int) $maxId);
        }

        $profiles = $query
            ->get()
            ->when(! $processAll, fn ($items) => $items->filter(function (Profile $profile) use ($duplicateLogoCounts, $duplicateThreshold): bool {
                $logo = trim((string) ($profile->logo_url ?? ''));
                $resolvedUrl = MediaUrl::publicImageUrl($profile->logo_url);
                $isDuplicate = $logo !== '' && (($duplicateLogoCounts[$logo] ?? 0) >= $duplicateThreshold);
                $isGeneratedFallback = GeneratedProfileLogo::isGenerated($logo);

                return $logo === '' || $resolvedUrl === null || $isDuplicate || $isGeneratedFallback;
            }))
            ->take($limit)
            ->values();

        if ($profiles->isEmpty()) {
            $this->info('Top20 profiles that need logo refresh were not found for selected filters.');

            return self::SUCCESS;
        }

        $this->line('Profile logo repair');
        $this->line('Mode: ' . ($dryRun ? 'dry-run' : 'write'));
        $this->line('Top20 fetch: ' . ($fetchTop20 ? 'enabled' : 'disabled'));
        $this->line('Order: ' . ($latestFirst ? 'latest first' : 'oldest first'));
        $this->line('Scope: ' . ($includeAllProfiles ? 'all profiles' : 'Top20-linked profiles'));
        $this->line('Fallback generation: ' . ($generateFallback ? 'enabled' : 'disabled'));
        if (filled($minId) || filled($maxId)) {
            $this->line('ID range: ' . (filled($minId) ? (string) $minId : '...') . ' - ' . (filled($maxId) ? (string) $maxId : '...'));
        }
        $this->line('Duplicate threshold: ' . $duplicateThreshold);
        $this->line('Profiles queued: ' . $profiles->count());
        $this->newLine();

        $stats = [
            'processed' => 0,
            'already_ok' => 0,
            'repaired' => 0,
            'resolved' => 0,
            'fallback' => 0,
            'missing' => 0,
        ];

        $rows = [];

        foreach ($profiles as $profile) {
            $result = $service->repairProfileLogo($profile, ! $dryRun, $fetchTop20, true);
            $status = (string) ($result['status'] ?? 'missing');

            if ($status === 'missing' && $generateFallback) {
                $fallbackPath = GeneratedProfileLogo::ensure($profile);
                $changed = $profile->logo_url !== $fallbackPath;

                if (! $dryRun && $changed) {
                    $profile->logo_url = $fallbackPath;
                    $profile->saveQuietly();
                }

                $result['status'] = 'fallback';
                $result['changed'] = $changed;
                $result['logo_url'] = $fallbackPath;
                $result['resolved_url'] = MediaUrl::publicImageUrl($fallbackPath);
                $result['source'] = 'generated_fallback';
                $status = 'fallback';
            }

            $stats['processed']++;
            if (array_key_exists($status, $stats)) {
                $stats[$status]++;
            }

            $rows[] = [
                (string) ($result['profile_id'] ?? $profile->id),
                (string) ($result['profile_name'] ?? $profile->name),
                $status,
                (string) ($result['source'] ?? '-'),
                (string) ($result['checked_candidates'] ?? 0),
                (string) ($result['logo_url'] ?? ''),
            ];
        }

        $this->table(
            ['ID', 'Profile', 'Status', 'Source', 'Candidates', 'Logo path'],
            $rows
        );

        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Processed', (string) $stats['processed']],
                ['Already OK', (string) $stats['already_ok']],
                ['Repaired', (string) $stats['repaired']],
                ['Resolved', (string) $stats['resolved']],
                ['Fallback generated', (string) $stats['fallback']],
                ['Still missing', (string) $stats['missing']],
            ]
        );

        return self::SUCCESS;
    }
}
