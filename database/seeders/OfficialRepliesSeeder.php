<?php

namespace Database\Seeders;

use App\Models\OfficialReply;
use App\Models\Profile;
use App\Models\ProfileReview;
use App\Models\User;
use Illuminate\Database\Seeder;

class OfficialRepliesSeeder extends Seeder
{
    public function run(): void
    {
        $author = User::query()->first();
        if (!$author) {
            return;
        }

        $profile = Profile::query()->where('slug', 'nova-market')->first();
        if (!$profile) {
            return;
        }

        $review = ProfileReview::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'published')
            ->orderBy('id')
            ->first();

        if (!$review) {
            return;
        }

        OfficialReply::query()->updateOrCreate(
            ['profile_review_id' => $review->id],
            [
                'profile_id' => $profile->id,
                'author_user_id' => $author->id,
                'body' => 'Дякуємо за зворотний звʼязок. Ми постійно працюємо над якістю сервісу та швидкістю обробки звернень.',
                'is_edited' => false,
                'edited_at' => null,
            ]
        );
    }
}
