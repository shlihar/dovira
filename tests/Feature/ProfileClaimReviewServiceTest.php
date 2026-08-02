<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\ProfileClaim;
use App\Models\User;
use App\Services\ProfileClaimReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileClaimReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_assigns_owner_and_rejects_competing_claims(): void
    {
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
    }
}
