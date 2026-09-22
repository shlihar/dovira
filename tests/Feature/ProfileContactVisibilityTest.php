<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\ProSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileContactVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_pro_unverified_profile_shows_basic_contacts_and_lead_request_cta(): void
    {
        $profile = Profile::query()->create([
            'name' => 'Non PRO Contact Probe',
            'slug' => 'non-pro-contact-probe',
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
            'is_pro' => false,
            'city' => 'Київ',
            'address' => 'вул. Прихована, 12',
            'website' => 'https://hidden-contact.example.com',
            'contact_cta_url' => 'https://t.me/hidden_contact_probe',
            'email' => 'hidden-contact@example.com',
            'phone' => '+380 67 111 22 33',
            'social_links' => [
                'telegram' => 'https://t.me/hidden_social_probe',
            ],
        ]);

        $this
            ->get(route('profile.show', ['slug' => $profile->slug]))
            ->assertOk()
            ->assertSee('Залишити заявку')
            ->assertSee('hidden-contact.example.com', false)
            ->assertSee('tel:+380671112233', false)
            ->assertSee('+380 67 111 22 33')
            ->assertSee('вул. Прихована, 12')
            ->assertDontSee('hidden-contact@example.com')
            ->assertDontSee('hidden_social_probe')
            ->assertDontSee('hidden_contact_probe');
    }

    public function test_non_pro_owner_verified_profile_shows_basic_contacts_but_not_extended_contacts(): void
    {
        $profile = Profile::query()->create([
            'name' => 'Verified Free Contact Probe',
            'slug' => 'verified-free-contact-probe',
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
            'is_pro' => false,
            'is_owner_verified' => true,
            'city' => 'Київ',
            'address' => 'вул. Без PRO, 21',
            'website' => 'https://verified-free-hidden.example.com',
            'contact_cta_url' => 'https://t.me/verified_free_hidden',
            'email' => 'verified-free-hidden@example.com',
            'phone' => '+380 67 222 33 44',
            'social_links' => [
                'telegram' => 'https://t.me/verified_free_social_hidden',
            ],
        ]);

        $this
            ->get(route('profile.show', ['slug' => $profile->slug]))
            ->assertOk()
            ->assertSee('Залишити заявку')
            ->assertSee('tel:+380672223344', false)
            ->assertSee('+380 67 222 33 44')
            ->assertSee('verified-free-hidden.example.com', false)
            ->assertSee('вул. Без PRO, 21')
            ->assertDontSee('verified-free-hidden@example.com')
            ->assertDontSee('verified_free_hidden')
            ->assertDontSee('verified_free_social_hidden');
    }

    public function test_pro_profile_shows_basic_and_extended_contacts(): void
    {
        $profile = Profile::query()->create([
            'name' => 'PRO Contact Probe',
            'slug' => 'pro-contact-probe',
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
            'is_pro' => true,
            'city' => 'Київ',
            'address' => 'вул. Відкрита, 7',
            'website' => 'https://visible-contact.example.com',
            'contact_cta_url' => 'https://t.me/visible_contact_probe',
            'email' => 'visible-contact@example.com',
            'phone' => '+380 67 444 55 66',
            'social_links' => [
                'telegram' => 'https://t.me/visible_social_probe',
            ],
        ]);

        $this
            ->get(route('profile.show', ['slug' => $profile->slug]))
            ->assertOk()
            ->assertSee('visible-contact.example.com', false)
            ->assertSee('visible-contact@example.com')
            ->assertSee('visible_contact_probe')
            ->assertSee('visible_social_probe')
            ->assertSee('tel:+380674445566', false)
            ->assertSee('вул. Відкрита, 7');
    }

    public function test_expired_pro_subscription_shows_only_basic_contacts(): void
    {
        $profile = Profile::query()->create([
            'name' => 'Expired PRO Contact Probe',
            'slug' => 'expired-pro-contact-probe',
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
            'is_pro' => true,
            'city' => 'Київ',
            'address' => 'вул. Прострочена, 5',
            'website' => 'https://expired-contact.example.com',
            'contact_cta_url' => 'https://t.me/expired_contact_probe',
            'email' => 'expired-contact@example.com',
            'phone' => '+380 67 777 88 99',
            'social_links' => [
                'telegram' => 'https://t.me/expired_social_probe',
            ],
        ]);

        ProSubscription::query()->create([
            'profile_id' => $profile->id,
            'plan' => 'business',
            'status' => 'active',
            'started_at' => now()->subMonths(2),
            'ends_at' => now()->subDay(),
        ]);

        $this
            ->get(route('profile.show', ['slug' => $profile->slug]))
            ->assertOk()
            ->assertSee('Залишити заявку')
            ->assertSee('expired-contact.example.com', false)
            ->assertSee('tel:+380677778899', false)
            ->assertSee('вул. Прострочена, 5')
            ->assertDontSee('expired-contact@example.com')
            ->assertDontSee('expired_contact_probe')
            ->assertDontSee('expired_social_probe');
    }
}
