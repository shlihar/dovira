<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Profile;
use App\Services\CatalogDataAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogDataAuditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_auditor_flags_and_fixes_category_slug_mismatch(): void
    {
        $parent = Category::query()->create([
            'name' => 'Юридичні послуги',
            'slug' => 'catalog-yurydychni-poslugy',
            'is_active' => true,
        ]);

        $lawyers = Category::query()->create([
            'parent_id' => $parent->id,
            'name' => 'Адвокати',
            'slug' => 'advokaty',
            'is_active' => true,
        ]);

        $notaries = Category::query()->create([
            'parent_id' => $parent->id,
            'name' => 'Нотаріуси',
            'slug' => 'notariusy',
            'is_active' => true,
        ]);

        $profile = Profile::query()->create([
            'name' => 'Адвокат Майнард Наталія Олександрівна',
            'slug' => 'advokat-mainard-nataliia-oleksandrivna',
            'city' => 'Кропивницький',
        ]);
        $profile->categories()->attach($parent->id, ['is_primary' => false]);
        $profile->categories()->attach($notaries->id, ['is_primary' => true]);

        $auditor = app(CatalogDataAuditor::class);
        $issues = $auditor->issuesFor($profile->load('categories'));

        $this->assertContains('category_slug_mismatch', array_column($issues, 'type'));
        $this->assertSame('Адвокати', collect($issues)->firstWhere('type', 'category_slug_mismatch')['suggested_category']['name']);

        $result = $auditor->audit($profile->id, fix: true);

        $this->assertSame(1, $result['fixed']);
        $profile->refresh();
        $this->assertTrue($profile->categories()->whereKey($lawyers->id)->wherePivot('is_primary', true)->exists());
        $this->assertFalse($profile->categories()->whereKey($notaries->id)->exists());
    }

    public function test_auditor_normalizes_registered_city_aliases(): void
    {
        $profile = Profile::query()->create([
            'name' => 'Тестовий профіль',
            'slug' => 'test-profile',
            'city' => 'Київ',
        ]);
        DB::table('profiles')->where('id', $profile->id)->update(['city' => 'Kiev']);

        $result = app(CatalogDataAuditor::class)->audit($profile->id, fix: true);

        $this->assertSame(1, $result['fixed']);
        $this->assertSame('Київ', $profile->refresh()->city);
    }

    public function test_auditor_flags_cities_outside_registry(): void
    {
        $profile = Profile::query()->create([
            'name' => 'Тестовий профіль',
            'slug' => 'test-profile',
            'city' => 'Неіснуюче Місто',
        ]);

        $issues = app(CatalogDataAuditor::class)->issuesFor($profile->load('categories'));

        $this->assertContains('city_not_in_registry', array_column($issues, 'type'));
    }
}
