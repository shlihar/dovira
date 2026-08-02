<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Profile;
use App\Models\Region;
use Illuminate\Database\Seeder;

class ProfilesSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = config('static_profiles', []);

        $categoryMap = [
            'nova-market' => 'Онлайн-магазини',
            'tech-hub-store' => 'Техніка',
            'citydent-clinic' => 'Клініки',
            'autocare-service' => 'Автосервіси',
            'green-delivery' => 'Доставка',
            'smarthome-store' => 'Техніка',
            'resto-family' => 'Ресторани',
            'bookflow' => 'Онлайн-магазини',
            'freshcare-pharmacy' => 'Аптеки',
            'quickbox-delivery' => 'Доставка',
            'tutorspace-academy' => 'Освіта',
            'buildcraft-studio' => 'Будівництво',
        ];

        foreach ($profiles as $slug => $data) {
            $region = null;
            if (!empty($data['city'])) {
                $region = Region::query()->firstWhere('name', $data['city'])
                    ?? Region::query()->firstWhere('name', 'м. Київ')
                    ?? Region::query()->first();
            }

            $profile = Profile::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'type' => 'company',
                    'name' => $data['name'] ?? ucfirst(str_replace('-', ' ', $slug)),
                    'description' => $data['about'] ?? null,
                    'website' => $data['website'] ?? null,
                    'email' => $data['email'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'address' => $data['address'] ?? null,
                    'city' => $data['city'] ?? null,
                    'district' => $data['district'] ?? null,
                    'logo_url' => $data['logo_url'] ?? null,
                    'region_id' => $region?->id,
                    'is_verified' => (bool) ($data['verified'] ?? false),
                    'is_pro' => (bool) ($data['pro'] ?? false),
                    'status' => 'active',
                    'rating_avg' => (float) ($data['rating'] ?? 0),
                    'reviews_count' => (int) ($data['reviews_count'] ?? 0),
                    'recommend_percent' => (int) ($data['recommend_percent'] ?? 0),
                ]
            );

            $categoryName = $categoryMap[$slug] ?? null;
            if ($categoryName) {
                $category = Category::query()->firstWhere('slug', str($categoryName)->slug()->toString());
                if ($category) {
                    $profile->categories()->syncWithoutDetaching([
                        $category->id => ['is_primary' => true],
                    ]);
                }
            }
        }
    }
}
