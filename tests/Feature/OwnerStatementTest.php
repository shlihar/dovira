<?php

namespace Tests\Feature;

use App\Filament\Resources\Profiles\Pages\EditProfile;
use App\Models\Profile;
use App\Models\ProSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Позиція компанії»: власник надсилає → модерація схвалює → текст видно
 * на сторінці. Головне, що перевіряємо: без схвалення текст НЕ публічний.
 */
class OwnerStatementTest extends TestCase
{
    use RefreshDatabase;

    private function proProfile(): Profile
    {
        $profile = Profile::query()->create([
            'name' => 'Компанія з досьє',
            'slug' => 'kompaniia-z-dosie',
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
            'dossier' => 'Текст досьє про компанію, зібраний з відкритих джерел.',
            'dossier_verdict' => 'yellow',
        ]);

        ProSubscription::query()->create([
            'profile_id' => $profile->id,
            'plan' => 'pro',
            'status' => 'active',
            'started_at' => now()->subDay(),
            'ends_at' => now()->addMonths(6),
        ]);
        $profile->update(['is_pro' => true]);

        return $profile->fresh();
    }

    public function test_owner_submits_statement_and_it_waits_for_moderation(): void
    {
        $owner = User::factory()->create();
        $profile = $this->proProfile();
        $profile->update(['owner_user_id' => $owner->id]);

        $text = 'У досьє згадана судова справа 2021 року. Вона завершена, рішення ухвалене на нашу користь, копію надали модерації.';

        $this->actingAs($owner)
            ->postJson(route('pro.account.profiles.owner-statement', $profile), ['statement' => $text])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $profile->refresh();
        $this->assertSame(Profile::OWNER_STATEMENT_PENDING, $profile->owner_statement_status);
        $this->assertNotNull($profile->owner_statement_submitted_at);
        $this->assertNull($profile->owner_statement_published_at);

        // Поки не схвалено — на публічній сторінці нічого немає.
        $this->get(route('profile.show', ['slug' => $profile->slug]))
            ->assertOk()
            ->assertDontSee('судова справа 2021 року')
            ->assertDontSee('Компанія надала свою позицію');
    }

    public function test_short_statement_is_rejected_by_validation(): void
    {
        $owner = User::factory()->create();
        $profile = $this->proProfile();
        $profile->update(['owner_user_id' => $owner->id]);

        $this->actingAs($owner)
            ->postJson(route('pro.account.profiles.owner-statement', $profile), ['statement' => 'Це неправда'])
            ->assertStatus(422);
    }

    public function test_statement_requires_active_subscription(): void
    {
        $owner = User::factory()->create();
        $profile = Profile::query()->create([
            'name' => 'Без підписки',
            'slug' => 'bez-pidpysky',
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
            'owner_user_id' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->postJson(route('pro.account.profiles.owner-statement', $profile), [
                'statement' => str_repeat('Текст позиції компанії для перевірки доступу. ', 2),
            ])
            ->assertStatus(403);
    }

    public function test_approved_statement_is_visible_with_badge(): void
    {
        $profile = $this->proProfile();
        $profile->forceFill([
            'owner_statement' => 'Ми оновили ліцензію у 2026 році, а згадана в досьє перевірка завершилась без стягнень.',
            'owner_statement_status' => Profile::OWNER_STATEMENT_APPROVED,
            'owner_statement_published_at' => now(),
        ])->save();

        $this->get(route('profile.show', ['slug' => $profile->slug]))
            ->assertOk()
            ->assertSee('Позиція компанії')
            ->assertSee('Компанія надала свою позицію')
            ->assertSee('перевірка завершилась без стягнень', false);
    }

    public function test_admin_approval_sets_publication_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $profile = $this->proProfile();
        $profile->forceFill([
            'owner_statement' => 'Текст позиції, який власник надіслав на перевірку модерації платформи.',
            'owner_statement_status' => Profile::OWNER_STATEMENT_PENDING,
            'owner_statement_submitted_at' => now()->subHour(),
        ])->save();

        Livewire::actingAs($admin)
            ->test(EditProfile::class, ['record' => $profile->getKey()])
            ->fillForm(['owner_statement_status' => Profile::OWNER_STATEMENT_APPROVED])
            ->call('save')
            ->assertHasNoFormErrors();

        $profile->refresh();
        $this->assertTrue($profile->hasPublishedOwnerStatement());
        $this->assertNotNull($profile->owner_statement_published_at);
    }
}
