<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\ProfileDataSource;
use App\Services\HomePageDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuarantineMixedProfilesTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function fixture(): array
    {
        $p = Profile::withoutEvents(fn () => Profile::create(['name' => 'Wrong shop', 'slug' => 'original-lawyer', 'status' => 'active', 'is_published' => true, 'show_in_catalog' => true]));
        $source = ProfileDataSource::create(['profile_id' => $p->id, 'source_type' => 'top20_import', 'title' => 'Top20 import · Lawyer', 'url' => 'https://top20.ua/lawyer.html', 'status' => 'imported']);
        $path = tempnam(sys_get_temp_dir(), 'identity-manifest-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, json_encode([['id' => $p->id, 'name' => $p->name, 'slug' => $p->slug, 'sources' => [$source->id => $source->title]]]));

        return [$p, $path];
    }

    public function test_preview_does_not_unpublish_or_write_notes(): void
    {
        [$p, $path] = $this->fixture();
        $this->artisan('dovira:quarantine-mixed-profiles', ['manifest' => $path])->assertSuccessful();
        $this->assertSame('active', $p->fresh()->status);
        $this->assertNull($p->fresh()->internal_note);
    }

    public function test_apply_backs_up_all_data_and_is_idempotent(): void
    {
        [$p, $path] = $this->fixture();
        $this->mock(HomePageDataService::class)->shouldReceive('refresh')->once();
        $this->artisan('dovira:quarantine-mixed-profiles', ['manifest' => $path, '--apply' => true])->assertSuccessful();
        $after = $p->fresh();
        $this->assertSame('draft', $after->status);
        $this->assertFalse($after->is_published);
        $this->assertFalse($after->show_in_catalog);
        $backup = storage_path('app/private/identity-repair/').explode('Backup: ', $after->internal_note)[1];
        $this->temporaryFiles[] = $backup;
        $snapshot = json_decode(gzdecode(file_get_contents($backup)), true);
        $this->assertSame('active', $snapshot['profiles'][0]['status']);
        $this->assertCount(1, $snapshot['profile_data_sources']);
        $this->assertDatabaseCount('profile_data_sources', 1);
        $this->artisan('dovira:quarantine-mixed-profiles', ['manifest' => $path, '--apply' => true])->assertSuccessful();
        $this->assertSame($after->internal_note, $p->fresh()->internal_note);
    }

    public function test_stale_manifest_is_rejected_without_changes(): void
    {
        [$p, $path] = $this->fixture();
        $p->name = 'Changed since review';
        $p->saveQuietly();
        try {
            $this->artisan('dovira:quarantine-mixed-profiles', ['manifest' => $path, '--apply' => true])->run();
            $this->fail('Stale manifest must not apply.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('review again', $e->getMessage());
            $this->assertSame('active', $p->fresh()->status);
        }
    }
}
