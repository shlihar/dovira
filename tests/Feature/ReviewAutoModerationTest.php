<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\ProfileReview;
use App\Services\ReviewAutoModerationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewAutoModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function service(): ReviewAutoModerationService
    {
        return app(ReviewAutoModerationService::class);
    }

    private function profile(): Profile
    {
        return Profile::query()->where('status', 'active')->firstOrFail();
    }

    public function test_clean_review_passes_to_moderation(): void
    {
        $verdict = $this->service()->evaluate(
            $this->profile(),
            'Дуже задоволений сервісом, консультували професійно і швидко вирішили питання.',
            '1.2.3.4',
            true,
        );

        $this->assertSame('pending', $verdict['status']);
        $this->assertFalse($verdict['is_suspicious']);
    }

    public function test_duplicate_review_is_rejected(): void
    {
        $profile = $this->profile();
        $text = 'Найкраща компанія у місті, рекомендую всім своїм знайомим без винятку.';

        ProfileReview::query()->create([
            'profile_id' => $profile->id,
            'author_name' => 'Хтось',
            'rating' => 5,
            'body' => $text,
            'status' => 'pending',
        ]);

        $verdict = $this->service()->evaluate($profile, $text, '1.2.3.4', true);

        $this->assertSame('rejected', $verdict['status']);
        $this->assertSame(100, $verdict['risk_score']);
    }

    public function test_review_with_multiple_links_is_rejected(): void
    {
        $verdict = $this->service()->evaluate(
            $this->profile(),
            'Заходьте на http://spam.top та www.buy-now.shop — знижки лише сьогодні!',
            '1.2.3.4',
            true,
        );

        $this->assertSame('rejected', $verdict['status']);
    }

    public function test_ip_flood_is_rejected(): void
    {
        $profile = $this->profile();

        for ($i = 0; $i < 10; $i++) {
            ProfileReview::query()->create([
                'profile_id' => $profile->id,
                'author_name' => 'Flood',
                'author_ip' => '9.9.9.9',
                'rating' => 5,
                'body' => "Унікальний текст відгуку номер {$i} з достатньою довжиною для валідації.",
                'status' => 'pending',
            ]);
        }

        $verdict = $this->service()->evaluate(
            $profile,
            'Ще один свіжий та цілком унікальний відгук від того самого відвідувача.',
            '9.9.9.9',
            true,
        );

        $this->assertSame('rejected', $verdict['status']);
    }

    public function test_all_caps_is_flagged_suspicious_but_not_rejected(): void
    {
        $verdict = $this->service()->evaluate(
            $this->profile(),
            'НАЙКРАЩА КОМПАНІЯ У ВСЬОМУ МІСТІ ОБОВЯЗКОВО ЗВЕРТАЙТЕСЯ ДО НИХ',
            '1.2.3.4',
            true,
        );

        $this->assertSame('pending', $verdict['status']);
        $this->assertTrue($verdict['is_suspicious']);
    }

    public function test_spam_review_is_stored_as_rejected_via_endpoint(): void
    {
        $profile = $this->profile();
        $text = 'Дубль тексту для перевірки автовідхилення через публічний ендпоінт.';

        ProfileReview::query()->create([
            'profile_id' => $profile->id,
            'author_name' => 'First',
            'rating' => 5,
            'body' => $text,
            'status' => 'pending',
        ]);

        $response = $this->postJson(route('reviews.store'), [
            'profile_slug' => $profile->slug,
            'rating' => 1,
            'body' => $text,
            'author_email' => 'spam@example.com',
        ]);

        $response->assertOk();

        // Гостю показуємо той самий нейтральний меседж — без підказки, що відхилено.
        $this->assertDatabaseHas('profile_reviews', [
            'profile_id' => $profile->id,
            'author_email' => 'spam@example.com',
            'status' => 'rejected',
        ]);
    }
}
