<?php

namespace Tests\Feature;

use App\Mail\ProfileClaimApprovedMail;
use App\Mail\ProNurtureEmail;
use App\Models\Profile;
use App\Models\ProfileClaim;
use App\Models\User;
use App\Services\Notifications\TelegramAdminNotifier;
use App\Services\ProfileClaimReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProfileClaimReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_assigns_owner_and_rejects_competing_claims(): void
    {
        Mail::fake();

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        $winner = User::factory()->create();
        $loser = User::factory()->create();

        $profile = Profile::query()->create([
            'name' => 'Atlas Group',
            'slug' => 'atlas-group',
            'type' => 'company',
            'status' => 'active',
        ]);

        $approvedClaim = ProfileClaim::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $winner->id,
            'status' => 'pending',
            'company_role' => 'Owner',
        ]);

        $competingClaim = ProfileClaim::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $loser->id,
            'status' => 'pending',
            'company_role' => 'Manager',
        ]);

        $this->actingAs($admin);

        $this->mock(TelegramAdminNotifier::class)
            ->shouldReceive('claimApproved')
            ->once()
            ->with(\Mockery::type(ProfileClaim::class), 'ручний апрув адміном');

        app(ProfileClaimReviewService::class)->approve($approvedClaim);

        $this->assertDatabaseHas('profile_claims', [
            'id' => $approvedClaim->id,
            'status' => 'approved',
            'reviewed_by_user_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('profile_claims', [
            'id' => $competingClaim->id,
            'status' => 'rejected',
            'reviewed_by_user_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('profiles', [
            'id' => $profile->id,
            'owner_user_id' => $winner->id,
            'is_owner_verified' => 1,
        ]);

        Mail::assertSent(ProfileClaimApprovedMail::class, fn (ProfileClaimApprovedMail $mail): bool => $mail->hasTo($winner->email)
            && $mail->profileName === 'Atlas Group');
        Mail::assertSent(ProNurtureEmail::class, fn (ProNurtureEmail $mail): bool => $mail->hasTo($winner->email)
            && $mail->step['cta_label'] === 'Оформити підписку');
    }

    public function test_manual_link_assigns_owner_and_notifies(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $owner = User::factory()->create(['email_notifications_enabled' => false]);

        $profile = Profile::query()->create([
            'name' => 'Orion Studio',
            'slug' => 'orion-studio',
            'type' => 'company',
            'status' => 'active',
        ]);

        $this->actingAs($admin);

        $this->mock(TelegramAdminNotifier::class)
            ->shouldReceive('claimApproved')
            ->once()
            ->with(\Mockery::type(ProfileClaim::class), 'ручна привʼязка адміном');

        app(ProfileClaimReviewService::class)->linkOwnerManually($profile, $owner);

        // Профіль привʼязано і позначено підтвердженим.
        $this->assertDatabaseHas('profiles', [
            'id' => $profile->id,
            'owner_user_id' => $owner->id,
            'is_owner_verified' => 1,
        ]);

        // Лишився слід у «Заявках» (approved), навіть без заявки від користувача.
        $this->assertDatabaseHas('profile_claims', [
            'profile_id' => $profile->id,
            'user_id' => $owner->id,
            'status' => 'approved',
            'reviewed_by_user_id' => $admin->id,
        ]);

        // Власник отримав in-app сповіщення про привʼязку.
        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $owner->id,
            'type' => 'pro_claim_approved',
        ]);

        Mail::assertSent(ProfileClaimApprovedMail::class, fn (ProfileClaimApprovedMail $mail): bool => $mail->hasTo($owner->email)
            && $mail->profileName === 'Orion Studio');
    }
}
