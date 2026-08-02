<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CategoryService;
use App\Models\OfficialReply;
use App\Models\PaymentOrder;
use App\Models\PlatformNotification;
use App\Models\Profile;
use App\Models\ProfileEvent;
use App\Models\ProfileReview;
use App\Models\ProSubscription;
use App\Models\ProSubscriptionPayment;
use App\Services\ProfileAnalyticsService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProAccountTest extends TestCase
{
    use RefreshDatabase;

    private function activatePro(Profile $profile): void
    {
        $profile->forceFill(['is_pro' => true])->save();

        ProSubscription::query()->create([
            'profile_id' => $profile->id,
            'plan' => 'business',
            'status' => 'active',
            'price_monthly' => 1499,
            'price_yearly' => 14990,
            'currency' => 'UAH',
            'started_at' => now()->subMonth(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    public function test_pro_account_requires_authentication(): void
    {
        $response = $this->get(route('pro.account'));

        $response->assertRedirect(route('login'));
    }

    public function test_owner_can_open_pro_account_for_owned_profile(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'active',
            'is_pro' => true,
            'website' => 'https://example.com',
            'phone' => '+380501112233',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('pro.account'));

        $response
            ->assertOk()
            ->assertSee('PRO кабінет')
            ->assertSee('Nova Legal')
            ->assertSee(route('profile.show', ['slug' => $profile->slug]));
    }

    public function test_unverified_user_sees_email_gate_without_claim_otp_widget(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'admin@dovira.test',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('pro.account', ['tab' => 'claims']));

        $response
            ->assertOk()
            ->assertSee('Підтвердіть email, щоб керувати профілями')
            ->assertSee('Надіслати лист повторно')
            ->assertDontSee('data-claim-otp', false);
    }

    public function test_claim_details_offer_support_confirmation_with_prefilled_profile_message(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'email_verified_at' => now(),
        ]);
        $profile = Profile::query()->create([
            'name' => 'Адвокатське бюро Довіра',
            'slug' => 'advokatske-biuro-dovira',
            'type' => 'company',
            'status' => 'active',
            'email' => 'office@example.com',
            'city' => 'Київ',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('pro.account', [
                'tab' => 'claims',
                'claim_profile' => $profile->id,
            ]));

        $response
            ->assertOk()
            ->assertSee('Підтвердити через підтримку')
            ->assertSee('https://t.me/dovira_support', false)
            ->assertSee('Хочу підтвердити права на профіль &quot;Адвокатське бюро Довіра&quot;', false)
            ->assertSee((string) $profile->id);
    }

    public function test_profile_form_prefills_generated_seo_fields_when_missing(): void
    {
        $user = User::factory()->create();

        $category = Category::query()->create([
            'name' => 'Адвокати',
            'slug' => 'lawyers',
            'status' => 'active',
            'is_active' => true,
        ]);

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'is_pro' => true,
            'name' => 'Адвокат Андрій Смирнов',
            'slug' => 'andrii-smirnov',
            'type' => 'company',
            'status' => 'active',
            'city' => 'Одеса',
            'short_description' => 'Юридична допомога для бізнесу та приватних клієнтів.',
        ]);

        $profile->categories()->sync([$category->id => ['is_primary' => true]]);

        $expectedTitle = 'Відгуки про Адвокат Андрій Смирнов — Одеса | DOVIRA';
        $expectedDescription = 'Відгуки про Адвокат Андрій Смирнов в Одесі. Юридична допомога для бізнесу та приватних клієнтів. Читайте досвід клієнтів на DOVIRA.';

        $response = $this
            ->actingAs($user)
            ->get(route('pro.account', ['tab' => 'profile', 'profile' => $profile->id]));

        $response
            ->assertOk()
            ->assertSee($expectedTitle)
            ->assertSee($expectedDescription);
    }

    public function test_owner_can_switch_overview_analytics_period(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'active',
            'is_pro' => true,
        ]);

        foreach (range(0, 29) as $daysAgo) {
            ProfileEvent::query()->create([
                'profile_id' => $profile->id,
                'visitor_id' => 'visitor-' . $daysAgo,
                'event_type' => ProfileAnalyticsService::EVENT_PROFILE_VIEW,
                'created_at' => now()->subDays($daysAgo)->setTime(12, 0),
                'updated_at' => now()->subDays($daysAgo)->setTime(12, 0),
            ]);
        }

        $response = $this
            ->actingAs($user)
            ->get(route('pro.account', [
                'tab' => 'overview',
                'profile' => $profile->id,
                'analytics_period' => 'last_7',
            ]));

        $response
            ->assertOk()
            ->assertSee('За 7 днів')
            ->assertSee('analytics_period=last_7', false);
    }

    public function test_owner_can_load_overview_analytics_via_json_endpoint(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'active',
            'is_pro' => true,
        ]);

        foreach (range(0, 6) as $daysAgo) {
            $event = new ProfileEvent([
                'profile_id' => $profile->id,
                'visitor_id' => 'analytics-' . $daysAgo,
                'event_type' => ProfileAnalyticsService::EVENT_PROFILE_VIEW,
            ]);
            $event->created_at = now()->subDays($daysAgo)->setTime(12, 0);
            $event->updated_at = now()->subDays($daysAgo)->setTime(12, 0);
            $event->save();
        }

        $response = $this
            ->actingAs($user)
            ->getJson(route('pro.account.analytics', [
                'profile' => $profile->id,
                'analytics_period' => 'last_7',
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('chart.period', 'last_7')
            ->assertJsonPath('summary.period_label', 'За 7 днів');

        $this->assertCount(7, $response->json('chart.values'));
    }

    public function test_non_pro_owner_does_not_see_private_analytics_ui(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal-free',
            'type' => 'company',
            'status' => 'active',
            'is_pro' => false,
        ]);

        ProfileEvent::query()->create([
            'profile_id' => $profile->id,
            'visitor_id' => 'free-profile-view',
            'event_type' => ProfileAnalyticsService::EVENT_PROFILE_VIEW,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('pro.account', [
                'tab' => 'analytics',
                'profile' => $profile->id,
            ]));

        $response
            ->assertOk()
            ->assertSee('data-pro-tab-panel="billing"', false)
            ->assertDontSee('data-pro-tab-target="analytics"', false)
            ->assertDontSee('data-pro-tab-panel="analytics"', false)
            ->assertDontSee('id="overview-analytics"', false)
            ->assertDontSee('Перегляди профілю');
    }

    public function test_owner_can_update_managed_profile(): void
    {
        $user = User::factory()->create();

        $category = Category::query()->create([
            'name' => 'Юристи',
            'slug' => 'lawyers',
            'status' => 'active',
            'is_active' => true,
        ]);

        $service = CategoryService::query()->create([
            'category_id' => $category->id,
            'name' => 'Консультації',
            'slug' => 'consulting',
            'is_active' => true,
        ]);

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'is_pro' => true,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'draft',
        ]);

        $response = $this
            ->actingAs($user)
            ->patch(route('pro.account.profiles.update', $profile), [
                'name' => 'Nova Legal Group',
                'slug' => 'nova-legal-group',
                'category_id' => $category->id,
                'service_ids' => [$service->id],
                'short_description' => 'Короткий опис',
                'description' => 'Повний опис компанії.',
                'logo_url' => 'https://example.com/logo.png',
                'banner_url' => 'https://example.com/banner.png',
                'gallery_urls' => "https://example.com/1.jpg\nhttps://example.com/2.jpg",
                'website' => 'https://example.com',
                // example.com is on the placeholder-host blocklist in WebsiteUrl.
                'contact_cta_url' => 'https://t.me/novalegal',
                'email' => 'team@example.com',
                'phone' => '+380500000001',
                'address' => 'Kyiv, Main street 1',
                'city' => 'Kyiv',
                'seo_title' => 'Nova Legal Group',
                'seo_description' => 'SEO description',
                'og_image_url' => 'https://example.com/og.png',
                'status' => 'active',
                'show_in_catalog' => '1',
            ]);

        $response->assertRedirect(route('pro.account', ['tab' => 'profile', 'profile' => $profile->id]));

        $profile->refresh();

        $this->assertSame('Nova Legal Group', $profile->name);
        $this->assertSame('nova-legal-group', $profile->slug);
        $this->assertSame('Короткий опис', $profile->short_description);
        $this->assertSame('Повний опис компанії.', $profile->description);
        $this->assertSame('https://example.com/logo.png', $profile->logo_url);
        $this->assertSame('https://example.com/banner.png', $profile->banner_url);
        $this->assertSame([
            ['url' => 'https://example.com/1.jpg', 'visible' => true, 'title' => ''],
            ['url' => 'https://example.com/2.jpg', 'visible' => true, 'title' => ''],
        ], $profile->gallery);
        $this->assertTrue($profile->is_published);
        $this->assertTrue($profile->show_in_catalog);
        $this->assertSame([$category->id], $profile->categories()->pluck('categories.id')->all());
        $this->assertSame([$service->id], $profile->services()->pluck('category_services.id')->all());
    }

    public function test_owner_can_update_managed_profile_via_ajax(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'is_pro' => true,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'draft',
        ]);

        $response = $this
            ->actingAs($user)
            ->patchJson(route('pro.account.profiles.update', $profile), [
                'name' => 'Nova Legal AJAX',
                'slug' => 'nova-legal-ajax',
                'short_description' => 'Короткий опис через AJAX',
                'description' => 'Розгорнутий опис через AJAX.',
                'phone' => '+38 (067) 123-45-67',
                'city' => 'Одеса, Україна',
                'social_youtube' => 'https://youtube.com/@novalegal',
                'social_viber' => 'https://invite.viber.com/example',
                'social_whatsapp' => 'https://wa.me/380671234567',
                'status' => 'draft',
                'show_in_catalog' => false,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('message', 'Зміни збережено.')
            ->assertJsonPath('profile.name', 'Nova Legal AJAX')
            ->assertJsonPath('profile.short_description', 'Короткий опис через AJAX');

        $profile->refresh();

        $this->assertSame('Nova Legal AJAX', $profile->name);
        $this->assertSame('nova-legal-ajax', $profile->slug);
        $this->assertSame('Короткий опис через AJAX', $profile->short_description);
        $this->assertSame('Розгорнутий опис через AJAX.', $profile->description);
        $this->assertSame('+38 (067) 123-45-67', $profile->phone);
        $this->assertSame('https://youtube.com/@novalegal', $profile->social_links['youtube'] ?? null);
        $this->assertSame('https://invite.viber.com/example', $profile->social_links['viber'] ?? null);
        $this->assertSame('https://wa.me/380671234567', $profile->social_links['whatsapp'] ?? null);
    }

    public function test_blank_seo_fields_are_auto_generated_and_synced_in_ajax_response(): void
    {
        $user = User::factory()->create();

        $category = Category::query()->create([
            'name' => 'Адвокати',
            'slug' => 'lawyers',
            'status' => 'active',
            'is_active' => true,
        ]);

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'is_pro' => true,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'draft',
        ]);

        $response = $this
            ->actingAs($user)
            ->patchJson(route('pro.account.profiles.update', $profile), [
                'name' => 'Адвокат Андрій Смирнов',
                'slug' => 'andrii-smirnov',
                'category_id' => $category->id,
                'short_description' => 'Юридична допомога для бізнесу та приватних клієнтів.',
                'city' => 'Одеса',
                'seo_title' => '',
                'seo_description' => '',
                'status' => 'draft',
                'show_in_catalog' => false,
            ]);

        $expectedTitle = 'Відгуки про Адвокат Андрій Смирнов — Одеса | DOVIRA';
        $expectedDescription = 'Відгуки про Адвокат Андрій Смирнов в Одесі. Юридична допомога для бізнесу та приватних клієнтів. Читайте досвід клієнтів на DOVIRA.';

        $response
            ->assertOk()
            ->assertJsonPath('profile.seo_title', $expectedTitle)
            ->assertJsonPath('profile.seo_description', $expectedDescription);

        $profile->refresh();

        $this->assertSame($expectedTitle, $profile->seo_title);
        $this->assertSame($expectedDescription, $profile->seo_description);
    }

    public function test_auto_generated_seo_description_ignores_placeholder_profile_copy(): void
    {
        $user = User::factory()->create();

        $category = Category::query()->create([
            'name' => 'Адвокати',
            'slug' => 'lawyers',
            'status' => 'active',
            'is_active' => true,
        ]);

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'is_pro' => true,
            'name' => 'Nova QA 72018в',
            'slug' => 'nova-qa-72018v',
            'type' => 'company',
            'status' => 'draft',
        ]);

        $response = $this
            ->actingAs($user)
            ->patchJson(route('pro.account.profiles.update', $profile), [
                'name' => 'Nova QA 72018в',
                'slug' => 'nova-qa-72018v',
                'category_id' => $category->id,
                'short_description' => 'Сучасний короткий опис для перевірки збереження.',
                'service_names' => ['Сімейне право', 'Кримінальне право'],
                'city' => 'Одеса',
                'seo_title' => '',
                'seo_description' => '',
                'status' => 'draft',
                'show_in_catalog' => false,
            ]);

        $expectedDescription = 'Відгуки про Nova QA 72018в, адвоката в Одесі. Сімейне право, кримінальне право. Контакти на DOVIRA.';

        $response
            ->assertOk()
            ->assertJsonPath('profile.seo_description', $expectedDescription);

        $profile->refresh();

        $this->assertSame($expectedDescription, $profile->seo_description);
        $this->assertStringNotContainsString('перевірки збереження', $profile->seo_description);
    }

    public function test_owner_can_upload_gallery_images_via_ajax(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'is_pro' => true,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'draft',
            'gallery' => [
                ['url' => 'https://example.com/existing.jpg', 'visible' => true, 'title' => ''],
            ],
        ]);

        $file = UploadedFile::fake()->image('office.jpg', 1200, 900);

        $response = $this
            ->actingAs($user)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->patch(route('pro.account.profiles.update', $profile), [
                'name' => 'Nova Legal',
                'slug' => 'nova-legal',
                'status' => 'draft',
                'gallery_urls' => "https://example.com/existing.jpg",
                'gallery_files' => [$file],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonCount(2, 'profile.media.gallery');

        $profile->refresh();

        $this->assertCount(2, $profile->gallery ?? []);
        $this->assertSame([
            'url' => 'https://example.com/existing.jpg',
            'visible' => true,
            'title' => '',
        ], $profile->gallery[0]);
        $this->assertStringStartsWith(sprintf('profiles/%d/gallery/', $profile->id), $profile->gallery[1]['url'] ?? '');
        Storage::disk('public')->assertExists($profile->gallery[1]['url'] ?? '');
    }

    public function test_owner_can_upload_profile_avatar_via_ajax(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'is_pro' => true,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'draft',
        ]);

        $file = UploadedFile::fake()->image('avatar.png', 600, 600);

        $response = $this
            ->actingAs($user)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->patch(route('pro.account.profiles.update', $profile), [
                'name' => 'Nova Legal',
                'slug' => 'nova-legal',
                'status' => 'draft',
                'logo_file' => $file,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $profile->refresh();

        $this->assertNotNull($profile->logo_url);
        $this->assertStringStartsWith(sprintf('profiles/%d/logo/', $profile->id), $profile->logo_url);
        Storage::disk('public')->assertExists($profile->logo_url);
        $this->assertNotNull(data_get($response->json(), 'profile.media.logo_public_url'));
    }

    public function test_owner_removes_uploaded_gallery_image_and_file_is_deleted(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'is_pro' => true,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'draft',
            'gallery' => [],
        ]);

        $storedPath = sprintf('profiles/%d/gallery/existing.webp', $profile->id);
        Storage::disk('public')->put($storedPath, 'fake-image');
        $profile->forceFill([
            'gallery' => [
                ['url' => $storedPath, 'visible' => true, 'title' => ''],
            ],
        ])->save();

        $response = $this
            ->actingAs($user)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->patch(route('pro.account.profiles.update', $profile), [
                'name' => 'Nova Legal',
                'slug' => 'nova-legal',
                'status' => 'draft',
                'gallery_urls' => '',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonCount(0, 'profile.media.gallery');

        $profile->refresh();

        $this->assertSame([], $profile->gallery ?? []);
        Storage::disk('public')->assertMissing($storedPath);
    }

    public function test_owner_can_update_gallery_visibility_via_ajax(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'is_pro' => true,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'draft',
            'gallery' => [
                ['url' => 'https://example.com/visible.jpg', 'visible' => true, 'title' => ''],
                ['url' => 'https://example.com/hidden.jpg', 'visible' => true, 'title' => ''],
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->patchJson(route('pro.account.profiles.update', $profile), [
                'name' => 'Nova Legal',
                'slug' => 'nova-legal',
                'status' => 'draft',
                'gallery_urls' => json_encode([
                    ['url' => 'https://example.com/visible.jpg', 'visible' => true, 'title' => ''],
                    ['url' => 'https://example.com/hidden.jpg', 'visible' => false, 'title' => ''],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('profile.media.gallery_items.0.visible', true)
            ->assertJsonPath('profile.media.gallery_items.1.visible', false);

        $profile->refresh();

        $this->assertSame([
            ['url' => 'https://example.com/visible.jpg', 'visible' => true, 'title' => ''],
            ['url' => 'https://example.com/hidden.jpg', 'visible' => false, 'title' => ''],
        ], $profile->gallery);
    }

    public function test_owner_can_update_notification_preferences_via_ajax(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'draft',
        ]);

        $response = $this
            ->actingAs($user)
            ->patchJson(route('pro.account.notifications.preferences', $profile), [
                'in_app_enabled' => true,
                'email_enabled' => false,
                'review_new_enabled' => false,
                'review_negative_enabled' => true,
                'billing_expiring_enabled' => true,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('preferences.email_enabled', false)
            ->assertJsonPath('preferences.review_new_enabled', false)
            ->assertJsonPath('preferences.review_negative_enabled', true);

        $profile->refresh();

        $this->assertFalse((bool) data_get($profile->notification_preferences, 'email_enabled'));
        $this->assertFalse((bool) data_get($profile->notification_preferences, 'review_new_enabled'));
        $this->assertTrue((bool) data_get($profile->notification_preferences, 'review_negative_enabled'));
    }

    public function test_owner_receives_platform_notification_for_new_negative_review(): void
    {
        $owner = User::factory()->create();
        $author = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'active',
            'notification_preferences' => [
                'in_app_enabled' => true,
                'review_negative_enabled' => true,
            ],
        ]);

        ProfileReview::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $author->id,
            'author_name' => 'Клієнт',
            'author_email' => 'client@example.com',
            'rating' => 1,
            'title' => 'Поганий досвід',
            'body' => 'Дуже незадоволений сервісом і комунікацією.',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $owner->id,
            'type' => 'pro_review_negative',
            'title' => 'Новий негативний відгук',
        ]);
    }

    public function test_owner_can_mark_profile_notifications_as_read(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'active',
        ]);

        PlatformNotification::query()->create([
            'user_id' => $user->id,
            'type' => 'pro_billing_paid',
            'title' => 'PRO-підписку активовано',
            'body' => 'Підписка активна.',
            'meta' => [
                'profile_id' => $profile->id,
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson(route('pro.account.notifications.read-all', $profile));

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $user->id,
            'type' => 'pro_billing_paid',
        ]);

        $this->assertNotNull(
            PlatformNotification::query()->where('user_id', $user->id)->first()?->read_at
        );
    }

    public function test_public_profile_uses_generated_seo_meta_when_custom_values_are_missing(): void
    {
        $category = Category::query()->create([
            'name' => 'Адвокати',
            'slug' => 'lawyers',
            'status' => 'active',
            'is_active' => true,
        ]);

        $profile = Profile::query()->create([
            'name' => 'Адвокат Андрій Смирнов',
            'slug' => 'andrii-smirnov',
            'type' => 'company',
            'status' => 'active',
            'city' => 'Одеса',
            'short_description' => 'Юридична допомога для бізнесу та приватних клієнтів.',
            'reviews_count' => 4,
            'rating_avg' => 4.8,
        ]);

        $profile->categories()->sync([$category->id => ['is_primary' => true]]);

        $expectedTitle = 'Відгуки про Адвокат Андрій Смирнов — Одеса | DOVIRA';
        $expectedDescription = 'Відгуки про Адвокат Андрій Смирнов в Одесі. Юридична допомога для бізнесу та приватних клієнтів. Читайте досвід клієнтів на DOVIRA.';

        $response = $this->get(route('profile.show', ['slug' => $profile->slug]));

        $response
            ->assertOk()
            ->assertSee('<title>' . e($expectedTitle) . '</title>', false)
            ->assertSee('<meta name="description" content="' . e($expectedDescription) . '">', false);
    }

    public function test_owner_can_add_custom_service_names_via_ajax(): void
    {
        $user = User::factory()->create();

        $category = Category::query()->create([
            'name' => 'Юристи',
            'slug' => 'lawyers',
            'status' => 'active',
            'is_active' => true,
        ]);

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'is_pro' => true,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'draft',
        ]);

        $response = $this
            ->actingAs($user)
            ->patchJson(route('pro.account.profiles.update', $profile), [
                'name' => 'Nova Legal',
                'slug' => 'nova-legal',
                'category_id' => $category->id,
                'service_names' => ['Сімейні спори', 'Податкові консультації'],
                'status' => 'draft',
                'show_in_catalog' => false,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertDatabaseHas('category_services', [
            'category_id' => $category->id,
            'name' => 'Сімейні спори',
            'show_in_catalog' => false,
        ]);

        $this->assertDatabaseHas('category_services', [
            'category_id' => $category->id,
            'name' => 'Податкові консультації',
            'show_in_catalog' => false,
        ]);

        $this->assertSame(
            ['Податкові консультації', 'Сімейні спори'],
            $profile->refresh()->services()->orderBy('name')->pluck('name')->all()
        );
    }

    public function test_owner_checkout_redirects_to_pro_payment_without_activating_subscription(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'active',
            'is_pro' => false,
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson(route('pro.account.billing.checkout', $profile), [
                'period' => 'halfyear',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'redirect')
            ->assertJsonPath('message', 'Оберіть спосіб оплати PRO.')
            ->assertJsonPath('profile_id', $profile->id)
            ->assertJsonPath('redirect_url', route('pro.account.billing.pay', $profile));

        $profile->refresh();

        $this->assertFalse($profile->is_pro);
        $this->assertSame(0, $profile->proSubscriptions()->count());
    }

    public function test_pro_payment_page_renders_card_payment_button(): void
    {
        config(['payments.pro.amount' => 1]);

        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal-pay-page',
            'type' => 'company',
            'status' => 'active',
            'is_pro' => false,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('pro.account.billing.pay', $profile));

        $response
            ->assertOk()
            ->assertSee('Перейти до оплати')
            ->assertSee('Безпечна оплата через monopay')
            ->assertSee('1 грн / 6 місяців')
            ->assertDontSee('$49 / 6 місяців')
            ->assertSee('static/assets/payments/google-pay-light.svg', false)
            ->assertSee('static/assets/payments/apple-pay-light.svg', false);
    }

    public function test_owner_can_start_pro_monopay_payment_order(): void
    {
        Http::fake([
            'api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'mono-invoice-123',
                'pageUrl' => 'https://pay.mbnk.biz/invoice/mono-invoice-123',
                'appUrl' => 'monobank://invoice/mono-invoice-123',
            ]),
        ]);

        config([
            'payments.monopay.token' => 'monopay-test-token',
            'payments.pro.amount' => 1999,
        ]);

        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'active',
            'is_pro' => false,
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('pro.account.billing.pay.monopay', $profile));

        $response->assertRedirect('https://pay.mbnk.biz/invoice/mono-invoice-123');

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->hasHeader('X-Token', 'monopay-test-token')
                && $request->url() === 'https://api.monobank.ua/api/merchant/invoice/create'
                && $payload['amount'] === 199900
                && $payload['ccy'] === 980
                && $payload['paymentType'] === 'debit'
                && $payload['withAppUrl'] === true
                && filled($payload['redirectUrl'] ?? null)
                && filled($payload['webHookUrl'] ?? null)
                && ($payload['merchantPaymInfo']['destination'] ?? '') === 'DOVIRA PRO — підписка на 6 місяців — Nova Legal';
        });

        $profile->refresh();

        $this->assertFalse($profile->is_pro);
        $this->assertDatabaseHas('payment_orders', [
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'method' => PaymentOrder::METHOD_MONOPAY,
            'product_type' => PaymentOrder::PRODUCT_PRO_SUBSCRIPTION,
            'status' => PaymentOrder::STATUS_PENDING,
            'amount' => '199900',
            'currency' => 'UAH',
            'provider_invoice_id' => 'mono-invoice-123',
        ]);
    }

    public function test_mobile_owner_is_sent_to_monopay_app_url_when_available(): void
    {
        Http::fake([
            'api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'mono-mobile-invoice-123',
                'pageUrl' => 'https://pay.mbnk.biz/invoice/mono-mobile-invoice-123',
                'appUrl' => 'monobank://invoice/mono-mobile-invoice-123',
            ]),
        ]);

        config([
            'payments.monopay.token' => 'monopay-test-token',
            'payments.pro.amount' => 1999,
        ]);

        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Mobile Clinic',
            'slug' => 'mobile-clinic',
            'type' => 'company',
            'status' => 'active',
            'is_pro' => false,
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Mobile/15E148')
            ->post(route('pro.account.billing.pay.monopay', $profile));

        $response->assertRedirect('monobank://invoice/mono-mobile-invoice-123');
    }

    public function test_owner_can_start_pro_monopay_payment_order_via_ajax(): void
    {
        Http::fake([
            'api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'mono-ajax-invoice-123',
                'pageUrl' => 'https://pay.mbnk.biz/invoice/mono-ajax-invoice-123',
                'appUrl' => 'https://mbnk.app/or/mono-ajax-invoice-123',
            ]),
        ]);

        config([
            'payments.monopay.token' => 'monopay-test-token',
            'payments.pro.amount' => 1999,
        ]);

        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Ajax Legal',
            'slug' => 'ajax-legal',
            'type' => 'company',
            'status' => 'active',
            'is_pro' => false,
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson(route('pro.account.billing.pay.monopay', $profile));

        $response
            ->assertOk()
            ->assertJsonPath('status', 'redirect')
            ->assertJsonPath('redirect_url', 'https://pay.mbnk.biz/invoice/mono-ajax-invoice-123');

        $this->assertDatabaseHas('payment_orders', [
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'method' => PaymentOrder::METHOD_MONOPAY,
            'status' => PaymentOrder::STATUS_PENDING,
            'provider_invoice_id' => 'mono-ajax-invoice-123',
        ]);
    }

    public function test_monopay_payment_without_token_redirects_back_instead_of_503(): void
    {
        config([
            'payments.monopay.token' => '',
            'payments.pro.amount' => 1999,
        ]);

        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal-no-token',
            'type' => 'company',
            'status' => 'active',
            'is_pro' => false,
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('pro.account.billing.pay.monopay', $profile));

        $response
            ->assertRedirect(route('pro.account.billing.pay', $profile))
            ->assertSessionHas('status', 'pro-billing-provider-unavailable');

        $this->assertDatabaseCount('payment_orders', 0);
    }

    public function test_monopay_api_error_keeps_subscription_inactive_and_shows_payment_error(): void
    {
        Http::fake([
            'api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'errorDescription' => 'Merchant unavailable',
            ], 503),
        ]);

        config([
            'payments.monopay.token' => 'monopay-test-token',
            'payments.pro.amount' => 1999,
        ]);

        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal-mono-error',
            'type' => 'company',
            'status' => 'active',
            'is_pro' => false,
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('pro.account.billing.pay.monopay', $profile));

        $response
            ->assertRedirect(route('pro.account.billing.pay', $profile))
            ->assertSessionHas('status', 'pro-billing-provider-error');

        $this->assertFalse($profile->refresh()->is_pro);
        $this->assertDatabaseHas('payment_orders', [
            'profile_id' => $profile->id,
            'status' => PaymentOrder::STATUS_FAILED,
            'provider_invoice_id' => null,
        ]);
    }

    public function test_active_pro_owner_can_open_renewal_payment_flow(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal-renew',
            'type' => 'company',
            'status' => 'active',
            'is_pro' => true,
        ]);

        ProSubscription::query()->create([
            'profile_id' => $profile->id,
            'plan' => 'pro',
            'status' => 'active',
            'billing_period' => 'halfyear',
            'price_monthly' => 1999,
            'price_yearly' => 1999,
            'currency' => 'UAH',
            'started_at' => now()->subMonth(),
            'ends_at' => now()->addMonths(5),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('pro.account', [
                'tab' => 'billing',
                'profile' => $profile->id,
            ]));

        $response
            ->assertOk()
            ->assertSee('Продовжити на 6 місяців')
            ->assertSee('data-switch-label="Продовжити PRO"', false);
    }

    public function test_paid_monopay_success_button_opens_pro_management_not_billing(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal-success',
            'type' => 'company',
            'status' => 'active',
            'is_pro' => true,
        ]);

        $order = PaymentOrder::query()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'token' => '22222222-2222-4222-8222-222222222222',
            'method' => PaymentOrder::METHOD_MONOPAY,
            'product_type' => PaymentOrder::PRODUCT_PRO_SUBSCRIPTION,
            'status' => PaymentOrder::STATUS_PAID,
            'amount' => '100',
            'currency' => 'UAH',
            'provider_invoice_id' => 'mono-paid-invoice-123',
            'paid_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('pro.account.billing.success', $order->token));

        $response
            ->assertOk()
            ->assertSee('Перейти до управління PRO')
            ->assertSee('href="' . route('pro.account', ['profile' => $profile->id]) . '"', false)
            ->assertDontSee('Відкрити білінг')
            ->assertDontSee('tab=billing');
    }

    public function test_monopay_webhook_activates_pro_subscription_once(): void
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $details = openssl_pkey_get_details($privateKey);

        config([
            'payments.monopay.webhook_public_key' => base64_encode((string) $details['key']),
            'payments.pro.amount' => 1999,
        ]);

        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'active',
            'is_pro' => false,
        ]);

        $order = PaymentOrder::query()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'token' => '11111111-1111-4111-8111-111111111111',
            'method' => PaymentOrder::METHOD_MONOPAY,
            'product_type' => PaymentOrder::PRODUCT_PRO_SUBSCRIPTION,
            'status' => PaymentOrder::STATUS_PENDING,
            'amount' => '199900',
            'currency' => 'UAH',
            'provider_invoice_id' => 'mono-invoice-123',
        ]);

        $payload = [
            'invoiceId' => 'mono-invoice-123',
            'status' => 'success',
            'amount' => 199900,
            'ccy' => 980,
            'finalAmount' => 199900,
            'reference' => $order->token,
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        openssl_sign($rawBody, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGN' => base64_encode($signature),
        ];

        $this
            ->call('POST', route('webhooks.monopay'), [], [], [], $server, $rawBody)
            ->assertOk();

        $this
            ->call('POST', route('webhooks.monopay'), [], [], [], $server, $rawBody)
            ->assertOk();

        $profile->refresh();
        $order->refresh();

        $this->assertTrue($order->isPaid());
        $this->assertTrue($profile->is_pro);
        $this->assertSame(1, $profile->proSubscriptions()->count());
        $this->assertSame(1, $profile->proSubscriptionPayments()->count());
        $this->assertDatabaseHas('pro_subscriptions', [
            'profile_id' => $profile->id,
            'status' => 'active',
            'billing_period' => 'halfyear',
        ]);
        $this->assertDatabaseHas('pro_subscription_payments', [
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => 'monopay',
            'reference' => 'MONO-INVOICE-123',
            'amount' => 1999,
            'currency' => 'UAH',
            'status' => 'paid',
        ]);
    }

    public function test_owner_can_cancel_test_subscription_from_billing(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'active',
            'is_pro' => true,
        ]);

        ProSubscription::query()->create([
            'profile_id' => $profile->id,
            'plan' => 'business',
            'status' => 'active',
            'billing_period' => 'month',
            'price_monthly' => 1499,
            'price_yearly' => 14990,
            'currency' => 'UAH',
            'started_at' => now()->subWeek(),
            'ends_at' => now()->addMonth(),
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson(route('pro.account.billing.cancel', $profile));

        $response
            ->assertOk()
            ->assertJsonPath('status', 'redirect')
            ->assertJsonPath('message', 'PRO-підписку вимкнено.')
            ->assertJsonPath('profile_id', $profile->id)
            ->assertJsonPath('subscription_status', 'canceled')
            ->assertJsonPath('redirect_url', route('pro.account', ['tab' => 'billing', 'profile' => $profile->id]));

        $profile->refresh();

        $this->assertFalse($profile->is_pro);
        $this->assertDatabaseHas('pro_subscriptions', [
            'profile_id' => $profile->id,
            'status' => 'canceled',
        ]);
    }

    public function test_owner_can_download_test_billing_receipt(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'active',
            'is_pro' => true,
        ]);

        $subscription = ProSubscription::query()->create([
            'profile_id' => $profile->id,
            'plan' => 'business',
            'status' => 'active',
            'billing_period' => 'month',
            'price_monthly' => 1499,
            'price_yearly' => 14990,
            'currency' => 'UAH',
            'started_at' => now()->subWeek(),
            'ends_at' => now()->addMonth(),
        ]);

        $payment = ProSubscriptionPayment::query()->create([
            'pro_subscription_id' => $subscription->id,
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => 'test_gateway',
            'reference' => 'TEST-RECEIPT-001',
            'plan' => 'business',
            'billing_period' => 'month',
            'description' => 'Тестова оплата PRO',
            'amount' => 1499,
            'currency' => 'UAH',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('pro.account.billing.receipt', $payment));

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/plain; charset=UTF-8')
            ->assertHeader('content-disposition');

        $content = $response->streamedContent();

        $this->assertStringContainsString('DOVIRA Receipt', $content);
        $this->assertStringContainsString('TEST-RECEIPT-001', $content);
    }

    public function test_reviews_tab_shows_latest_review_first_on_initial_render(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'active',
            'is_pro' => true,
        ]);

        $reviewOld = ProfileReview::query()->create([
            'profile_id' => $profile->id,
            'author_name' => 'Старий відгук',
            'rating' => 4,
            'body' => 'Перший відгук.',
            'status' => 'published',
            'external_review_date' => '2026-05-10',
            'published_at' => now()->subDays(20),
            'created_at' => now()->subDays(20),
            'updated_at' => now()->subDays(20),
        ]);

        $reviewNewest = ProfileReview::query()->create([
            'profile_id' => $profile->id,
            'author_name' => 'Найновіший відгук',
            'rating' => 5,
            'body' => 'Другий відгук.',
            'status' => 'published',
            'external_review_date' => '2026-06-15',
            'published_at' => now()->subDays(10),
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        $reviewWithoutExternalDate = ProfileReview::query()->create([
            'profile_id' => $profile->id,
            'author_name' => 'Локальний відгук',
            'rating' => 3,
            'body' => 'Третій відгук.',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id]));

        $response->assertOk();

        $html = $response->getContent();

        $newestPosition = strpos($html, 'data-review-id="' . $reviewNewest->id . '"');
        $oldPosition = strpos($html, 'data-review-id="' . $reviewOld->id . '"');
        $localPosition = strpos($html, 'data-review-id="' . $reviewWithoutExternalDate->id . '"');

        $this->assertNotFalse($newestPosition);
        $this->assertNotFalse($oldPosition);
        $this->assertNotFalse($localPosition);
        $this->assertTrue($newestPosition < $oldPosition);
        $this->assertTrue($oldPosition < $localPosition);
    }

    public function test_owner_can_reply_to_review_from_pro_account(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'active',
        ]);
        $this->activatePro($profile);

        $review = ProfileReview::query()->create([
            'profile_id' => $profile->id,
            'author_name' => 'Анна',
            'author_email' => 'anna@example.com',
            'rating' => 5,
            'body' => 'Дуже хороший сервіс.',
            'status' => 'published',
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('pro.account.reviews.reply', $review), [
                'body' => 'Дякуємо за ваш відгук і довіру до нашої команди.',
            ]);

        $response->assertRedirect(route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id]));

        $reply = OfficialReply::query()->where('profile_review_id', $review->id)->first();

        $this->assertNotNull($reply);
        $this->assertSame($user->id, $reply->author_user_id);
        $this->assertSame('Дякуємо за ваш відгук і довіру до нашої команди.', $reply->body);
    }

    public function test_owner_can_reply_to_review_from_pro_account_via_ajax(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'active',
        ]);
        $this->activatePro($profile);

        $review = ProfileReview::query()->create([
            'profile_id' => $profile->id,
            'author_name' => 'Анна',
            'author_email' => 'anna@example.com',
            'rating' => 5,
            'body' => 'Дуже хороший сервіс.',
            'status' => 'published',
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson(route('pro.account.reviews.reply', $review), [
                'body' => 'Дякуємо за ваш відгук і довіру до нашої команди.',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('message', 'Офіційну відповідь збережено.')
            ->assertJsonPath('review_id', $review->id)
            ->assertJsonPath('redirect_url', route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id]));

        $reply = OfficialReply::query()->where('profile_review_id', $review->id)->first();

        $this->assertNotNull($reply);
        $this->assertSame($user->id, $reply->author_user_id);
        $this->assertSame('Дякуємо за ваш відгук і довіру до нашої команди.', $reply->body);
    }

    public function test_owner_can_hide_review_from_pro_account(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'active',
        ]);
        $this->activatePro($profile);

        $review = ProfileReview::query()->create([
            'profile_id' => $profile->id,
            'author_name' => 'Анна',
            'author_email' => 'anna@example.com',
            'rating' => 4,
            'body' => 'Все добре.',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->patch(route('pro.account.reviews.visibility', $review), [
                'status' => 'hidden',
            ]);

        $response->assertRedirect(route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id]));

        $review->refresh();
        $profile->refresh();

        $this->assertSame('hidden', $review->status);
        $this->assertSame(0, (int) $profile->reviews_count);
        $this->assertSame(0.0, (float) $profile->rating_avg);
    }

    public function test_owner_can_hide_review_from_pro_account_via_ajax(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nova Legal',
            'slug' => 'nova-legal',
            'type' => 'company',
            'status' => 'active',
        ]);
        $this->activatePro($profile);

        $review = ProfileReview::query()->create([
            'profile_id' => $profile->id,
            'author_name' => 'Анна',
            'author_email' => 'anna@example.com',
            'rating' => 4,
            'body' => 'Все добре.',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->patchJson(route('pro.account.reviews.visibility', $review), [
                'status' => 'hidden',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('message', 'Видимість відгуку оновлено.')
            ->assertJsonPath('review_id', $review->id)
            ->assertJsonPath('review_status', 'hidden')
            ->assertJsonPath('redirect_url', route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id]));

        $review->refresh();
        $profile->refresh();

        $this->assertSame('hidden', $review->status);
        $this->assertSame(0, (int) $profile->reviews_count);
        $this->assertSame(0.0, (float) $profile->rating_avg);
    }

    public function test_owner_without_pro_cannot_hide_review(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Basic Legal',
            'slug' => 'basic-legal',
            'type' => 'company',
            'status' => 'active',
        ]);

        $review = ProfileReview::query()->create([
            'profile_id' => $profile->id,
            'author_name' => 'Анна',
            'rating' => 4,
            'body' => 'Все добре.',
            'status' => 'published',
            'published_at' => now(),
        ]);

        // Керування видимістю відгуків — платна частина керування профілем.
        $this->actingAs($user)
            ->patch(route('pro.account.reviews.visibility', $review), [
                'status' => 'hidden',
            ])
            ->assertForbidden();

        $this->assertSame('published', $review->refresh()->status);
    }

    public function test_owner_without_pro_cannot_publish_official_reply(): void
    {
        $user = User::factory()->create();

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Basic Legal',
            'slug' => 'basic-legal',
            'type' => 'company',
            'status' => 'active',
        ]);

        $review = ProfileReview::query()->create([
            'profile_id' => $profile->id,
            'author_name' => 'Анна',
            'rating' => 5,
            'body' => 'Дуже хороший сервіс.',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('pro.account.reviews.reply', $review), [
                'body' => 'Дякуємо за відгук.',
            ])
            ->assertForbidden();
    }
}
