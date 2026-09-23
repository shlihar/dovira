<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\EmailLog;
use App\Models\PaymentOrder;
use App\Models\Profile;
use App\Models\ProfileClaim;
use App\Models\ProfileEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OutreachContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_top_profiles_has_three_independent_message_options(): void
    {
        $sequences = collect(config('outreach.sequences'));
        $sequence = $sequences->firstWhere('id', 'top_profiles');
        $this->assertSame('Топові', $sequence['label']);
        $this->assertTrue($sequence['alternatives']);
        $this->assertCount(3, $sequence['steps']);
        foreach ($sequence['steps'] as $step) {
            $this->assertArrayNotHasKey('day', $step);
            $this->assertStringContainsString('{імя}', $step['body']);
        }
        $ids = $sequences->flatMap(fn ($item) => array_column($item['steps'], 'id'));
        $this->assertSame($ids->count(), $ids->unique()->count());
        $this->actingAs($this->staff())->get(route('outreach'))
            ->assertOk()
            ->assertSee('top_profiles')
            ->assertSee('Варіант 1 · знайомство')
            ->assertSee('if (seq.alternatives)', false);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    private function makeProfile(array $attributes = []): Profile
    {
        return Profile::query()->create(array_merge([
            'name' => 'Nova Legal',
            'slug' => 'nova-legal-outreach-' . uniqid(),
            'type' => 'company',
            'status' => 'active',
        ], $attributes));
    }

    public function test_staff_can_save_email_into_empty_profile(): void
    {
        $profile = $this->makeProfile(['email' => null]);

        $this->actingAs($this->staff())
            ->postJson(route('outreach.profile-email', $profile), ['email' => 'hello@example.com'])
            ->assertOk()
            ->assertJson(['ok' => true, 'email' => 'hello@example.com']);

        $this->assertSame('hello@example.com', $profile->refresh()->email);
    }

    public function test_staff_can_clear_email(): void
    {
        $profile = $this->makeProfile(['email' => 'old@example.com']);

        $this->actingAs($this->staff())
            ->postJson(route('outreach.profile-email', $profile), ['email' => ''])
            ->assertOk()
            ->assertJson(['ok' => true, 'email' => null]);

        $this->assertNull($profile->refresh()->email);
    }

    public function test_staff_can_save_telegram_nick(): void
    {
        $profile = $this->makeProfile(['email' => 'keep@example.com', 'social_links' => null]);

        $this->actingAs($this->staff())
            ->postJson(route('outreach.profile-email', $profile), ['telegram' => '@nova_legal'])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'telegram' => 'https://t.me/nova_legal',
            ]);

        $this->assertSame('https://t.me/nova_legal', $profile->refresh()->social_links['telegram'] ?? null);
        $this->assertSame('keep@example.com', $profile->email);
    }

    public function test_saving_telegram_clears_no_telegram_flag(): void
    {
        $profile = $this->makeProfile([
            'social_links' => null,
            'outreach_flags' => [
                'no_telegram' => true,
                'no_telegram_marked_at' => now()->subDay()->toIso8601String(),
                'no_telegram_marked_by' => 123,
            ],
        ]);

        $this->actingAs($this->staff())
            ->postJson(route('outreach.profile-email', $profile), ['telegram' => '@nova_legal'])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'telegram' => 'https://t.me/nova_legal',
                'flags' => [],
            ]);

        $profile->refresh();

        $this->assertSame('https://t.me/nova_legal', $profile->social_links['telegram'] ?? null);
        $this->assertNull($profile->outreach_flags);
    }

    public function test_staff_can_clear_telegram(): void
    {
        $profile = $this->makeProfile(['social_links' => ['telegram' => 'https://t.me/old_nick']]);

        $this->actingAs($this->staff())
            ->postJson(route('outreach.profile-email', $profile), ['telegram' => ''])
            ->assertOk()
            ->assertJson(['ok' => true, 'telegram' => null]);

        $this->assertNull($profile->refresh()->social_links);
    }

    public function test_invalid_email_is_rejected(): void
    {
        $profile = $this->makeProfile(['email' => 'keep@example.com']);

        $this->actingAs($this->staff())
            ->postJson(route('outreach.profile-email', $profile), ['email' => 'not-an-email'])
            ->assertStatus(422);

        $this->assertSame('keep@example.com', $profile->refresh()->email);
    }

    public function test_invalid_telegram_is_rejected(): void
    {
        $profile = $this->makeProfile(['social_links' => ['telegram' => 'https://t.me/keep_nick']]);

        $this->actingAs($this->staff())
            ->postJson(route('outreach.profile-email', $profile), ['telegram' => 'https://example.com/nope'])
            ->assertStatus(422);

        $this->assertSame('https://t.me/keep_nick', $profile->refresh()->social_links['telegram'] ?? null);
    }

    public function test_non_staff_cannot_save_email(): void
    {
        $profile = $this->makeProfile(['email' => null]);
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->postJson(route('outreach.profile-email', $profile), ['email' => 'hello@example.com'])
            ->assertForbidden();

        $this->assertNull($profile->refresh()->email);
    }

    public function test_staff_can_mark_no_telegram_flag(): void
    {
        $profile = $this->makeProfile();

        $this->actingAs($this->staff())
            ->postJson(route('outreach.profile-flags', $profile), ['no_telegram' => true])
            ->assertOk()
            ->assertJsonPath('flags.no_telegram', true);

        $this->assertTrue((bool) data_get($profile->refresh()->outreach_flags, 'no_telegram'));
    }

    public function test_notifications_page_lists_claims_and_payments(): void
    {
        $owner = User::factory()->create(['name' => 'Оля Власник', 'email' => 'owner@example.com']);
        $profile = $this->makeProfile(['owner_user_id' => $owner->id]);

        $claimant = User::factory()->create(['name' => 'Ігор Заявник', 'email' => 'claim@example.com']);
        \App\Models\ProfileClaim::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $claimant->id,
            'status' => 'pending',
            'company_role' => 'Керуючий партнер',
        ]);

        \App\Models\PaymentOrder::query()->create([
            'user_id' => $owner->id,
            'profile_id' => $profile->id,
            'token' => (string) \Illuminate\Support\Str::uuid(),
            'method' => \App\Models\PaymentOrder::METHOD_MONOPAY,
            'product_type' => \App\Models\PaymentOrder::PRODUCT_PRO_SUBSCRIPTION,
            'status' => \App\Models\PaymentOrder::STATUS_PAID,
            'amount' => '199900',
            'currency' => 'UAH',
            'paid_at' => now(),
        ]);

        $this->actingAs($this->staff())
            ->get(route('outreach.notifications'))
            ->assertOk()
            ->assertSee('Заявка на підтвердження прав')
            ->assertSee('claim@example.com')
            ->assertSee('Керуючий партнер')
            ->assertSee('Оплата PRO')
            ->assertSee('1,999 UAH');
    }

    public function test_notifications_page_requires_staff(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->get(route('outreach.notifications'))
            ->assertForbidden();
    }

    public function test_outreach_list_exposes_owner_and_claims(): void
    {
        $category = Category::query()->create(['name' => 'Адвокати', 'slug' => 'advokaty-' . uniqid()]);

        $owner = User::factory()->create(['name' => 'Оля Власник', 'email' => 'owner-list@example.com']);
        $profile = $this->makeProfile(['owner_user_id' => $owner->id]);
        $profile->categories()->attach($category->id, ['is_primary' => true]);

        $claimant = User::factory()->create(['email' => 'claim-list@example.com']);
        \App\Models\ProfileClaim::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $claimant->id,
            'status' => 'pending',
        ]);

        // Дані адвокатів вбудовані у сторінку як JSON (LAWYERS) — перевіряємо,
        // що власник і заявник туди потрапили.
        $this->actingAs($this->staff())
            ->get(route('outreach'))
            ->assertOk()
            ->assertSee('owner-list@example.com')
            ->assertSee('claim-list@example.com');
    }

    public function test_outreach_status_endpoint_returns_clicked_and_no_telegram_flags(): void
    {
        $category = Category::query()->create(['name' => 'Адвокати', 'slug' => 'advokaty-status-' . uniqid()]);
        $profile = $this->makeProfile([
            'outreach_flags' => ['no_telegram' => true],
        ]);
        $profile->categories()->attach($category->id, ['is_primary' => true]);

        ProfileEvent::query()->create([
            'profile_id' => $profile->id,
            'event_type' => 'profile_view',
            'visitor_id' => 'visitor-outreach-status',
            'utm_source' => 'outreach',
            'utm_medium' => 'email',
            'utm_campaign' => 'test-campaign',
            'utm_content' => 'd2_red',
        ]);

        $this->actingAs($this->staff())
            ->getJson(route('outreach.status'))
            ->assertOk()
            ->assertJsonPath('profiles.0.id', $profile->id)
            ->assertJsonPath('profiles.0.clicked', true)
            ->assertJsonPath('profiles.0.no_telegram', true)
            ->assertJsonPath('profiles.0.account', 'none')
            ->assertJsonPath('profiles.0.paid', false);
    }

    public function test_email_stats_page_shows_category_and_clicked_profiles(): void
    {
        $category = Category::query()->create(['name' => 'Стоматологи', 'slug' => 'stomatology-' . uniqid()]);
        $profile = $this->makeProfile([
            'name' => 'Smile Clinic',
            'slug' => 'smile-clinic-email-stats',
            'email' => 'clinic@example.com',
            'city' => 'Київ',
            'is_owner_verified' => true,
        ]);
        $profile->categories()->attach($category->id, ['is_primary' => true]);

        EmailLog::query()->create([
            'to_email' => 'clinic@example.com',
            'subject' => 'Ваш профіль на Dovira',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        ProfileEvent::query()->create([
            'profile_id' => $profile->id,
            'event_type' => 'profile_view',
            'visitor_id' => 'visitor-email-stats',
            'utm_source' => 'outreach',
            'utm_medium' => 'email',
            'utm_campaign' => 'test-campaign',
            'utm_content' => 'd1_green',
        ]);

        $this->actingAs($this->staff())
            ->get(route('outreach.email-stats'))
            ->assertOk()
            ->assertSee('Email-статистика розсилок')
            ->assertSee('Стоматологи')
            ->assertSee('Smile Clinic')
            ->assertSee('clinic@example.com')
            ->assertSee('підтвердив');
    }
}
