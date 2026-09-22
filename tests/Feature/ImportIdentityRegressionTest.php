<?php

namespace Tests\Feature;

use App\Console\Commands\ImportGoogleMapsPlaces;
use App\Models\Profile;
use App\Models\ProfileDataSource;
use App\Services\Top20BulkImportService;
use App\Support\ImportedProfileIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class ImportIdentityRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function invoke(string $method, mixed ...$args): mixed
    {
        $service = app(Top20BulkImportService::class);

        return (new ReflectionMethod($service, $method))->invoke($service, ...$args);
    }

    private function profile(array $fields = []): Profile
    {
        return Profile::withoutEvents(fn () => Profile::create(array_merge([
            'name' => 'Pantera salon', 'slug' => 'pantera-salon', 'city' => 'Київ',
            'address' => 'Тестова, 1', 'phone' => '+380501112233', 'website' => 'https://shared.example',
        ], $fields)));
    }

    private function source(Profile $p, string $url, string $name): void
    {
        ProfileDataSource::create(['profile_id' => $p->id, 'source_type' => 'top20_import',
            'url' => $url, 'title' => 'Top20 import · '.$name, 'status' => 'imported']);
    }

    public function test_shared_phone_and_website_never_merge_unrelated_names(): void
    {
        $this->profile();
        $this->assertNull($this->invoke('findExistingProfile', 'https://top20.ua/other.html', [
            'name' => 'Aysban restaurant', 'city' => 'Київ', 'address' => 'Тестова, 1',
            'phones' => ['+380501112233'], 'website' => 'https://shared.example',
        ], []));
    }

    public function test_same_name_at_a_different_address_does_not_merge_branches(): void
    {
        $this->profile();
        $this->assertNull($this->invoke('findExistingProfile', 'https://top20.ua/branch.html', [
            'name' => 'Pantera salon', 'city' => 'Київ', 'address' => 'Тестова, 2',
        ], []));
    }

    public function test_exact_name_and_location_matches_only_one_profile(): void
    {
        $p = $this->profile();
        $details = ['name' => $p->name, 'city' => $p->city, 'address' => $p->address];
        $this->assertSame($p->id, $this->invoke('findExistingProfile', 'https://top20.ua/a.html', $details, [])->id);
        $this->profile(['slug' => 'pantera-duplicate']);
        $this->assertNull($this->invoke('findExistingProfile', 'https://top20.ua/a.html', $details, []));
    }

    public function test_existing_source_does_not_authorize_a_different_business_name(): void
    {
        $p = $this->profile();
        $url = 'https://top20.ua/a.html';
        $this->source($p, $url, $p->name);
        $this->expectException(\RuntimeException::class);
        $this->invoke('findExistingProfile', $url, ['name' => 'Other shop', 'city' => $p->city], []);
    }

    public function test_mixed_historical_sources_block_even_a_matching_current_name(): void
    {
        $p = $this->profile();
        $this->source($p, 'https://top20.ua/a.html', $p->name);
        $this->source($p, 'https://top20.ua/b.html', 'Other shop');
        $this->expectException(\RuntimeException::class);
        $this->invoke('findExistingProfile', 'https://top20.ua/a.html', ['name' => $p->name], []);
    }

    public function test_non_refresh_import_preserves_existing_name(): void
    {
        $p = new Profile(['name' => 'Original company', 'slug' => 'original-company']);
        $this->invoke('fillProfileFromImport', $p, ['name' => 'Different company'], false);
        $this->assertSame('Original company', $p->name);
    }

    public function test_google_shared_phone_is_not_an_identity_match(): void
    {
        $command = app(ImportGoogleMapsPlaces::class);
        $key = (new ReflectionMethod($command, 'phoneKey'))->invoke($command, '+380501112233');
        $result = (new ReflectionMethod($command, 'matchExistingProfile'))->invoke($command, [
            'placeId' => 'other', 'title' => 'Different clinic', 'city' => 'Львів', 'phone' => '+380501112233',
        ], ['by_place' => [], 'by_phone' => [$key => 42], 'by_city_tokens' => []]);
        $this->assertSame([null, null], $result);
    }

    public function test_quarantined_business_cannot_be_refreshed(): void
    {
        $p = new Profile(['name' => 'Company', 'internal_note' => '[IDENTITY_REPAIR_REVIEW]']);
        $this->expectException(\RuntimeException::class);
        ImportedProfileIdentity::assertSameBusiness($p, 'Company');
    }

    public function test_contact_parser_ignores_other_companies_in_page_addresses(): void
    {
        $html = '<div id="company-show" data-id="20"></div><script>var addresses = '.json_encode([
            ['company' => ['id' => 10, 'name' => 'Other'], 'address' => 'Wrong address', 'phones' => [['phone_to_call' => '1234567890']]],
            ['company' => ['id' => 20, 'name' => 'Correct'], 'address' => 'Correct address', 'phones' => [['phone_to_call' => '380501112233']]],
        ]).';</script>';
        $parsed = $this->invoke('extractTop20ContactData', $html);
        $this->assertSame(['380501112233'], $parsed['phones']);
        $this->assertSame(['Correct address'], $parsed['addresses']);
        $this->assertSame('Correct', $this->invoke('extractTop20VisibleProfileData', $html)['name']);
    }

    public function test_new_top20_reviews_keep_source_url_and_reimport_is_idempotent(): void
    {
        $p = $this->profile();
        $details = ['top20_url' => 'https://top20.ua/a.html', 'reviews' => [[
            '_rid' => 'review-123', 'review_author' => 'Test author', 'review_rating' => 5,
            'review_text' => 'Helpful detailed review from the actual business source.', 'review_date' => '2026-09-01',
        ]]];
        Profile::withoutEvents(function () use ($p, $details): void {
            $this->invoke('syncExternalReviews', $p, $details);
            $this->invoke('syncExternalReviews', $p, $details);
        });
        $this->assertDatabaseCount('profile_reviews', 1);
        $this->assertDatabaseHas('profile_reviews', ['profile_id' => $p->id,
            'external_source_url' => 'https://top20.ua/a.html#company-review-123', 'external_source_type' => 'top20']);
    }

    public function test_source_cannot_be_stolen_or_deleted_from_another_profile(): void
    {
        $a = $this->profile();
        $b = $this->profile(['slug' => 'second']);
        $this->source($a, 'https://top20.ua/a.html', $a->name);
        try {
            $this->invoke('syncTop20ProfileDataSource', $b, 'https://top20.ua/a.html', [], ['name' => $b->name]);
            $this->fail('Import should reject reassignment.');
        } catch (\RuntimeException $e) {
            $this->assertDatabaseHas('profile_data_sources', ['profile_id' => $a->id, 'url' => 'https://top20.ua/a.html']);
        }
    }
}
