<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\ProfileReview;
use App\Models\OfficialReply;
use App\Models\ReviewReply;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileReviewFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_profile_page_contains_review_popup_and_official_reply_block(): void
    {
        $response = $this->get(route('profile.show', ['slug' => 'nova-market']));

        $response->assertOk();
        $response->assertSee('Додати відгук');
        $response->assertSee('Опублікувати');
        $response->assertSee('Офіційна відповідь');
    }

    public function test_guest_cannot_submit_review_without_authentication(): void
    {
        $profile = Profile::query()->where('slug', 'nova-market')->firstOrFail();

        $response = $this->post(route('profile.reviews.store', ['slug' => $profile->slug]), [
            'author_name' => 'Тестовий Користувач',
            'rating' => 5,
            'body' => 'Дуже детально описую свій реальний досвід взаємодії з сервісом цієї компанії.',
        ]);

        $response->assertRedirectContains('/login');
    }

    public function test_authenticated_user_can_submit_review_and_it_is_saved_as_pending(): void
    {
        $profile = Profile::query()->where('slug', 'nova-market')->firstOrFail();
        $user = User::query()->firstOrFail();

        $response = $this->actingAs($user)
            ->post(route('profile.reviews.store', ['slug' => $profile->slug]), [
                'rating' => 5,
                'body' => 'Дуже детально описую свій реальний досвід взаємодії з сервісом цієї компанії.',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('review_submitted');

        $this->assertDatabaseHas('profile_reviews', [
            'profile_id' => $profile->id,
            'author_name' => $user->name,
            'status' => 'pending',
        ]);
    }

    public function test_review_can_be_submitted_with_rating_only(): void
    {
        $profile = Profile::query()->where('slug', 'nova-market')->firstOrFail();
        $user = User::query()->firstOrFail();

        $response = $this->actingAs($user)
            ->from(route('profile.show', ['slug' => $profile->slug]))
            ->post(route('profile.reviews.store', ['slug' => $profile->slug]), [
                'rating' => 5,
                'body' => '',
            ]);

        $response->assertRedirect(route('profile.show', ['slug' => $profile->slug]));
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('review_submitted');

        $this->assertDatabaseHas('profile_reviews', [
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'rating' => 5,
            'body' => '',
            'status' => 'pending',
        ]);
    }

    public function test_pending_review_is_not_visible_in_public_reviews_list(): void
    {
        $profile = Profile::query()->where('slug', 'nova-market')->firstOrFail();

        ProfileReview::query()->create([
            'profile_id' => $profile->id,
            'author_name' => 'Прихований Автор',
            'author_email' => 'hidden@example.com',
            'rating' => 4,
            'body' => 'Цей відгук має бути прихований до проходження модерації в системі.',
            'status' => 'pending',
        ]);

        $response = $this->get(route('profile.show', ['slug' => $profile->slug]));
        $response->assertOk();
        $response->assertDontSee('Прихований Автор');
    }

    public function test_deleting_profile_also_deletes_its_reviews(): void
    {
        $profile = Profile::query()->create([
            'name' => 'Тимчасовий профіль',
            'slug' => 'temp-profile-'.str()->lower((string) str()->uuid()),
            'type' => 'company',
            'status' => 'active',
        ]);

        $review = ProfileReview::query()->create([
            'profile_id' => $profile->id,
            'author_name' => 'Тестовий автор',
            'author_email' => 'cascade@example.com',
            'rating' => 5,
            'body' => 'Перевірка каскадного видалення відгуків разом із профілем.',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $profile->delete();

        $this->assertDatabaseMissing('profiles', [
            'id' => $profile->id,
        ]);

        $this->assertDatabaseMissing('profile_reviews', [
            'id' => $review->id,
        ]);
    }

    public function test_authenticated_user_can_comment_on_published_review(): void
    {
        $profile = Profile::query()->where('slug', 'nova-market')->firstOrFail();
        $review = ProfileReview::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'published')
            ->firstOrFail();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(
            route('profile.reviews.replies.store', ['slug' => $profile->slug, 'review' => $review]),
            ['body' => 'Підтверджую, у мене був схожий досвід співпраці.']
        );

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reply.author', $user->name);

        $this->assertDatabaseHas('review_replies', [
            'profile_review_id' => $review->id,
            'author_user_id' => $user->id,
            'body' => 'Підтверджую, у мене був схожий досвід співпраці.',
            'status' => 'published',
        ]);
    }

    public function test_authenticated_user_can_reply_to_existing_comment(): void
    {
        $profile = Profile::query()->where('slug', 'nova-market')->firstOrFail();
        $review = ProfileReview::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'published')
            ->firstOrFail();
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $parentReply = ReviewReply::query()->create([
            'profile_review_id' => $review->id,
            'profile_id' => $profile->id,
            'author_user_id' => $firstUser->id,
            'body' => 'Початковий коментар під відгуком.',
            'is_official' => false,
            'status' => 'published',
        ]);

        $response = $this->actingAs($secondUser)->postJson(
            route('profile.reviews.replies.store', ['slug' => $profile->slug, 'review' => $review]),
            [
                'body' => 'Відповідаю на ваш коментар.',
                'parent_id' => $parentReply->id,
            ]
        );

        $response
            ->assertOk()
            ->assertJsonPath('reply.parent_id', $parentReply->id);
    }

    public function test_official_reply_is_rendered_before_public_comments(): void
    {
        $profile = Profile::query()->where('slug', 'nova-market')->firstOrFail();
        $review = ProfileReview::query()->create([
            'profile_id' => $profile->id,
            'author_name' => 'Автор тестового відгуку',
            'author_email' => 'thread-order@example.com',
            'rating' => 5,
            'body' => 'Тестовий відгук для перевірки порядку коментарів.',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $owner = User::factory()->create(['name' => 'Команда профіля']);
        $commenter = User::factory()->create(['name' => 'Публічний коментатор']);

        OfficialReply::query()->create([
            'profile_review_id' => $review->id,
            'profile_id' => $profile->id,
            'author_user_id' => $owner->id,
            'body' => 'Офіційна відповідь профіля має бути першою у треді.',
        ]);

        ReviewReply::query()->create([
            'profile_review_id' => $review->id,
            'profile_id' => $profile->id,
            'author_user_id' => $commenter->id,
            'body' => 'Звичайний коментар має йти після офіційної відповіді.',
            'is_official' => false,
            'status' => 'published',
        ]);

        $this->get(route('profile.show', ['slug' => $profile->slug]))
            ->assertOk()
            ->assertDontSee('Команда профіля')
            ->assertSeeInOrder([
                $profile->name,
                'Офіційна відповідь профіля має бути першою у треді.',
                'Звичайний коментар має йти після офіційної відповіді.',
            ]);
    }

    public function test_authenticated_user_can_toggle_like_and_dislike_on_review(): void
    {
        $profile = Profile::query()->where('slug', 'nova-market')->firstOrFail();
        $review = ProfileReview::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'published')
            ->firstOrFail();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('profile.reviews.reactions.toggle', ['slug' => $profile->slug, 'review' => $review]), [
                'reaction' => 'like',
            ])
            ->assertOk()
            ->assertJsonPath('reaction', 'like')
            ->assertJsonPath('like_count', 1)
            ->assertJsonPath('dislike_count', 0);

        $this->assertDatabaseHas('profile_review_reactions', [
            'profile_review_id' => $review->id,
            'user_id' => $user->id,
            'reaction' => 'like',
        ]);

        $this->actingAs($user)
            ->postJson(route('profile.reviews.reactions.toggle', ['slug' => $profile->slug, 'review' => $review]), [
                'reaction' => 'dislike',
            ])
            ->assertOk()
            ->assertJsonPath('reaction', 'dislike')
            ->assertJsonPath('like_count', 0)
            ->assertJsonPath('dislike_count', 1);

        $this->assertDatabaseHas('profile_review_reactions', [
            'profile_review_id' => $review->id,
            'user_id' => $user->id,
            'reaction' => 'dislike',
        ]);

        $review->refresh();
        $this->assertSame(0, (int) $review->like_count);
        $this->assertSame(1, (int) $review->dislike_count);
    }

    public function test_authenticated_user_can_toggle_like_and_dislike_on_review_comment(): void
    {
        $profile = Profile::query()->where('slug', 'nova-market')->firstOrFail();
        $review = ProfileReview::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'published')
            ->firstOrFail();
        $commenter = User::factory()->create();
        $voter = User::factory()->create();

        $reply = ReviewReply::query()->create([
            'profile_review_id' => $review->id,
            'profile_id' => $profile->id,
            'author_user_id' => $commenter->id,
            'body' => 'Коментар для перевірки реакцій користувачів.',
            'is_official' => false,
            'status' => 'published',
        ]);

        $this->actingAs($voter)
            ->postJson(route('profile.reviews.replies.reactions.toggle', [
                'slug' => $profile->slug,
                'review' => $review,
                'reply' => $reply,
            ]), ['reaction' => 'like'])
            ->assertOk()
            ->assertJsonPath('reaction', 'like')
            ->assertJsonPath('like_count', 1)
            ->assertJsonPath('dislike_count', 0);

        $this->actingAs($voter)
            ->postJson(route('profile.reviews.replies.reactions.toggle', [
                'slug' => $profile->slug,
                'review' => $review,
                'reply' => $reply,
            ]), ['reaction' => 'dislike'])
            ->assertOk()
            ->assertJsonPath('reaction', 'dislike')
            ->assertJsonPath('like_count', 0)
            ->assertJsonPath('dislike_count', 1);

        $reply->refresh();
        $this->assertSame(0, (int) $reply->like_count);
        $this->assertSame(1, (int) $reply->dislike_count);
    }

    public function test_authenticated_user_can_react_to_official_reply(): void
    {
        $profile = Profile::query()->where('slug', 'nova-market')->firstOrFail();
        $review = ProfileReview::query()->create([
            'profile_id' => $profile->id,
            'author_name' => 'Автор з офіційною відповіддю',
            'author_email' => 'official-reaction@example.com',
            'rating' => 5,
            'body' => 'Відгук для перевірки реакцій на офіційну відповідь.',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $owner = User::factory()->create();
        $voter = User::factory()->create();

        $officialReply = OfficialReply::query()->create([
            'profile_review_id' => $review->id,
            'profile_id' => $profile->id,
            'author_user_id' => $owner->id,
            'body' => 'Офіційна відповідь для перевірки реакцій.',
        ]);

        $this->actingAs($voter)
            ->postJson(route('profile.reviews.official-reply.reactions.toggle', [
                'slug' => $profile->slug,
                'review' => $review,
            ]), ['reaction' => 'like'])
            ->assertOk()
            ->assertJsonPath('reaction', 'like')
            ->assertJsonPath('like_count', 1)
            ->assertJsonPath('dislike_count', 0);

        $officialReply->refresh();
        $this->assertSame(1, (int) $officialReply->like_count);
        $this->assertSame(0, (int) $officialReply->dislike_count);
    }

    public function test_authenticated_user_can_report_review(): void
    {
        $profile = Profile::query()->where('slug', 'nova-market')->firstOrFail();
        $review = ProfileReview::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'published')
            ->firstOrFail();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('profile.reviews.reports.store', [
                'slug' => $profile->slug,
                'review' => $review,
            ]), ['reason' => 'Публічна скарга на відгук'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('review_reports', [
            'profile_review_id' => $review->id,
            'reporter_user_id' => $user->id,
            'reason' => 'Публічна скарга на відгук',
            'status' => 'open',
        ]);
    }

    public function test_guest_cannot_comment_or_react_without_authentication(): void
    {
        $profile = Profile::query()->where('slug', 'nova-market')->firstOrFail();
        $review = ProfileReview::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'published')
            ->firstOrFail();

        $this->postJson(route('profile.reviews.replies.store', ['slug' => $profile->slug, 'review' => $review]), [
            'body' => 'Гість не може коментувати.',
        ])->assertUnauthorized();

        $this->postJson(route('profile.reviews.reactions.toggle', ['slug' => $profile->slug, 'review' => $review]), [
            'reaction' => 'like',
        ])->assertUnauthorized();
    }
}
