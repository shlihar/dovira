<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Models\ProSubscription;
use Illuminate\Database\Seeder;

class ProSubscriptionsSeeder extends Seeder
{
    public function run(): void
    {
        $novaMarketId = Profile::query()->where('slug', 'nova-market')->value('id');
        if ($novaMarketId) {
            ProSubscription::query()->updateOrCreate(
                ['profile_id' => $novaMarketId],
                [
                    'plan' => 'business',
                    'status' => 'active',
                    'price_monthly' => 1499,
                    'price_yearly' => 14990,
                    'currency' => 'UAH',
                    'started_at' => now()->subMonths(2),
                    'ends_at' => now()->addMonths(10),
                ]
            );
        }

        $techHubStoreId = Profile::query()->where('slug', 'tech-hub-store')->value('id');
        if ($techHubStoreId) {
            ProSubscription::query()->updateOrCreate(
                ['profile_id' => $techHubStoreId],
                [
                    'plan' => 'business',
                    'status' => 'active',
                    'price_monthly' => 1499,
                    'price_yearly' => 14990,
                    'currency' => 'UAH',
                    'started_at' => now()->subMonth(),
                    'ends_at' => now()->addMonths(11),
                ]
            );
        }
    }
}
