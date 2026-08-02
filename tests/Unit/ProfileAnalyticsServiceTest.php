<?php

namespace Tests\Unit;

use App\Models\Profile;
use App\Models\ProfileEvent;
use App\Services\ProfileAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_summary_returns_full_daily_series_for_selected_period(): void
    {
        $profile = Profile::query()->create([
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'active',
        ]);

        foreach (range(0, 29) as $daysAgo) {
            $event = new ProfileEvent([
                'profile_id' => $profile->id,
                'visitor_id' => 'visitor-' . $daysAgo,
                'event_type' => ProfileAnalyticsService::EVENT_PROFILE_VIEW,
            ]);
            $event->created_at = now()->subDays($daysAgo)->setTime(12, 0);
            $event->updated_at = now()->subDays($daysAgo)->setTime(12, 0);
            $event->save();
        }

        $service = app(ProfileAnalyticsService::class);

        $lastThirtyDays = $service->analyticsSummary($profile, 'last_30');
        $lastSevenDays = $service->analyticsSummary($profile, 'last_7');

        $this->assertCount(30, $lastThirtyDays['metrics']['views']['sparkline']);
        $this->assertSame(30, $lastThirtyDays['views']);
        $this->assertSame(30, $lastThirtyDays['metrics']['views']['current']);
        $this->assertSame(30, $lastThirtyDays['period']['days_count']);

        $this->assertCount(7, $lastSevenDays['metrics']['views']['sparkline']);
        $this->assertSame(7, $lastSevenDays['views']);
        $this->assertSame(7, $lastSevenDays['metrics']['views']['current']);
        $this->assertSame(7, $lastSevenDays['period']['days_count']);
    }
}
