<?php

namespace App\Console\Commands;

use App\Services\CatalogDataAuditor;
use Illuminate\Console\Command;

class AuditCatalogData extends Command
{
    protected $signature = 'dovira:audit-catalog-data
        {--profile-id= : Check only one profile}
        {--limit=0 : Maximum profiles to inspect, 0 means all}
        {--visible : Check only visible catalog profiles}
        {--fix : Apply conservative fixes when the suggested category/city is unambiguous}';

    protected $description = 'Audit catalog profiles for category/slug mismatches, primary category conflicts, and unregistered cities.';

    public function handle(CatalogDataAuditor $auditor): int
    {
        $profileId = filled($this->option('profile-id')) ? (int) $this->option('profile-id') : null;
        $limit = max(0, (int) $this->option('limit'));
        $visible = (bool) $this->option('visible');
        $fix = (bool) $this->option('fix');

        $result = $auditor->audit($profileId, $limit, $visible, $fix);

        $this->info('Catalog data audit completed.');
        $this->line('Checked profiles: ' . $result['checked']);
        $this->line('Fixed profiles: ' . $result['fixed']);
        $this->line('Mode: ' . ($fix ? 'write' : 'dry-run'));

        if ($result['counts'] === []) {
            $this->info('No issues found.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Issue counts:');
        foreach ($result['counts'] as $type => $count) {
            $this->line(" - {$type}: {$count}");
        }

        $this->newLine();
        $this->table(
            ['ID', 'Type', 'Severity', 'Name', 'Slug', 'City', 'Suggested'],
            collect($result['issues'])
                ->take(50)
                ->map(fn (array $issue): array => [
                    $issue['profile_id'],
                    $issue['type'],
                    $issue['severity'],
                    str($issue['name'])->limit(44),
                    str($issue['slug'])->limit(44),
                    $issue['city'] ?: '—',
                    data_get($issue, 'suggested_category.name', '—'),
                ])
                ->all()
        );

        if (count($result['issues']) > 50) {
            $this->line('Showing first 50 issues.');
        }

        return self::SUCCESS;
    }
}
