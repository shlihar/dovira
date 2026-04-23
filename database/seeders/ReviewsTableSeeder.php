<?php

namespace Database\Seeders;

use App\Models\Lawyer;
use App\Models\Review;
use Illuminate\Database\Seeder;

class ReviewsTableSeeder extends Seeder
{
    public function run(): void
    {
        $lawyer = Lawyer::first();

        if (!$lawyer) {
            return;
        }

        Review::create([
            'lawyer_id' => $lawyer->id,
            'author_name' => 'Іван Петренко',
            'author_email' => 'ivan@example.com',
            'rating' => 5,
            'body' => 'Швидко відреагував, допоміг вирішити питання. Рекомендую.',
            'status' => 'published',
        ]);

        Review::create([
            'lawyer_id' => $lawyer->id,
            'author_name' => 'Марія Коваленко',
            'author_email' => 'maria@example.com',
            'rating' => 4,
            'body' => 'Консультація була змістовна, трохи затримались зі строками.',
            'status' => 'published',
        ]);
    }
}
