<?php

namespace Tests\Feature;

use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileRecommendationStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_red_dossier_profile_is_marked_as_not_recommended_on_create(): void
    {
        $profile = Profile::query()->create([
            'name' => 'Red Dossier Profile',
            'slug' => 'red-dossier-profile',
            'dossier_verdict' => 'red',
            'dovira_recommendation_status' => 'recommend',
            'dovira_recommends' => true,
        ]);

        $profile->refresh();

        $this->assertSame('not_recommend', $profile->dovira_recommendation_status);
        $this->assertFalse($profile->dovira_recommends);
    }

    public function test_profile_is_marked_as_not_recommended_when_dossier_becomes_red(): void
    {
        $profile = Profile::query()->create([
            'name' => 'Changed Dossier Profile',
            'slug' => 'changed-dossier-profile',
            'dossier_verdict' => 'green',
            'dovira_recommendation_status' => 'recommend',
            'dovira_recommends' => true,
        ]);

        $profile->update([
            'dossier_verdict' => 'red',
        ]);

        $profile->refresh();

        $this->assertSame('not_recommend', $profile->dovira_recommendation_status);
        $this->assertFalse($profile->dovira_recommends);
    }
};
