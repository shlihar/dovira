<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\ProfileClaim;
use App\Models\User;
use App\Services\Notifications\TelegramAdminNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProAccountClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_submit_profile_claim_from_pro_account(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'name' => 'Vertex Dental',
            'slug' => 'vertex-dental',
            'type' => 'company',
            'status' => 'active',
        ]);

        $this->mock(TelegramAdminNotifier::class)
            ->shouldReceive('newClaim')
            ->once()
            ->with(\Mockery::type(ProfileClaim::class), false);

        $response = $this
            ->actingAs($user)
            ->post(route('pro.account.claims.store'), [
                'profile_id' => $profile->id,
                'company_role' => 'Owner',
                'proof_document_url' => 'https://example.com/ownership',
                'note' => 'I manage this company profile.',
            ]);

        // Ручна заявка лишається в заявках: підтримка перевірить права і
        // надішле пряме посилання на оплату після апруву.
        $response
            ->assertRedirect(route('pro.account', ['tab' => 'claims', 'claim_profile' => $profile->id]))
            ->assertSessionHas('status', 'claim-submitted');

        $this->assertDatabaseHas('profile_claims', [
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'company_role' => 'Owner',
        ]);
    }

    public function test_existing_claim_is_updated_instead_of_duplicated(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'name' => 'Nova Clinic',
            'slug' => 'nova-clinic',
            'type' => 'company',
            'status' => 'active',
        ]);

        ProfileClaim::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'status' => 'need_more_info',
            'company_role' => 'Manager',
            'note' => 'Old note',
        ]);

        $this->mock(TelegramAdminNotifier::class)
            ->shouldReceive('newClaim')
            ->once()
            ->with(\Mockery::type(ProfileClaim::class), true);

        $response = $this
            ->actingAs($user)
            ->post(route('pro.account.claims.store'), [
                'profile_id' => $profile->id,
                'company_role' => 'Director',
                'proof_document_url' => 'https://example.com/new-proof',
                'note' => 'Updated note',
            ]);

        $response
            ->assertRedirect(route('pro.account', ['tab' => 'claims', 'claim_profile' => $profile->id]))
            ->assertSessionHas('status', 'claim-updated');

        $this->assertDatabaseCount('profile_claims', 1);
        $this->assertDatabaseHas('profile_claims', [
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'company_role' => 'Director',
            'note' => 'Updated note',
        ]);
    }

    public function test_user_cannot_start_checkout_for_profile_owned_by_someone_else(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();

        $profile = Profile::query()->create([
            'name' => 'Owned Clinic',
            'slug' => 'owned-clinic',
            'type' => 'company',
            'status' => 'active',
            'owner_user_id' => $owner->id,
            'is_owner_verified' => true,
        ]);

        $this->mock(TelegramAdminNotifier::class)
            ->shouldNotReceive('newClaim');

        $this
            ->actingAs($user)
            ->post(route('pro.account.claims.store'), [
                'profile_id' => $profile->id,
                'company_role' => 'Owner',
            ])
            ->assertRedirect(route('profile.show', ['slug' => $profile->slug]))
            ->assertSessionHas('status', 'claim-link-taken');

        $this->assertDatabaseMissing('profile_claims', [
            'profile_id' => $profile->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_user_can_create_owned_profile_draft_from_pro_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('pro.account.profiles.store'), [
                'name' => 'New Client Channel',
                'city' => 'Київ',
                'website' => 'https://example.com',
                'email' => 'hello@example.com',
                'phone' => '+380501112233',
                'short_description' => 'Новий профіль для каталогу.',
            ]);

        $profile = Profile::query()->where('name', 'New Client Channel')->firstOrFail();

        // Профіль створюється безкоштовно як чернетка; редагування відкриє PRO.
        $response
            ->assertRedirect(route('pro.account', ['tab' => 'profile', 'profile' => $profile->id]))
            ->assertSessionHas('status', 'pro-profile-created');

        $this->assertSame($user->id, (int) $profile->owner_user_id);
        $this->assertSame('draft', $profile->status);
        $this->assertFalse((bool) $profile->is_published);
        $this->assertFalse((bool) $profile->show_in_catalog);
        $this->assertTrue((bool) $profile->is_owner_verified);
        $this->assertSame('https://example.com', $profile->website);
    }
}
