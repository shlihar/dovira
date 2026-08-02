<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Models\ProfileReview;
use Illuminate\Database\Seeder;

class ProfileReviewsSeeder extends Seeder
{
    public function run(): void
    {
        $reviewsByProfile = [
            'nova-market' => [
                ['Ірина Коваль', 5, 'Дуже зручний сервіс і швидка доставка.', null],
                ['Олег Мельник', 4, 'Якісно, але хотілося б більше варіантів оплати.', null],
                ['Тестовий Клієнт', 5, 'Сайт став значно зручніший: швидко знайшов профіль, залишив відгук через popup і все спрацювало без зайвих кроків.', 'Тестовий відгук для popup flow'],
            ],
            'tech-hub-store' => [
                ['Наталія Романчук', 5, 'Офіційний сервіс, швидко вирішили моє питання.', null],
                ['Юлія Павленко', 4, 'Хороша комунікація й підтримка після покупки.', null],
            ],
            'citydent-clinic' => [
                ['Катерина Шевченко', 5, 'Професійна консультація і добрий сервіс.', null],
            ],
        ];

        foreach ($reviewsByProfile as $slug => $reviews) {
            $profile = Profile::query()->firstWhere('slug', $slug);
            if (!$profile) {
                continue;
            }

            foreach ($reviews as [$author, $rating, $body, $title]) {
                ProfileReview::query()->updateOrCreate(
                    [
                        'profile_id' => $profile->id,
                        'author_name' => $author,
                        'body' => $body,
                    ],
                    [
                        'author_email' => null,
                        'rating' => $rating,
                        'title' => $title ?: null,
                        'status' => 'published',
                        'is_verified_purchase' => false,
                        'published_at' => now(),
                    ]
                );
            }

            $published = $profile->reviews()->where('status', 'published');
            $profile->update([
                'reviews_count' => (int) $published->count(),
                'rating_avg' => round((float) ($published->avg('rating') ?? 0), 2),
            ]);
        }
    }
}
