<?php

namespace Tests\Feature;

use App\Filament\Resources\Profiles\Pages\EditProfile;
use App\Models\Profile;
use App\Models\ProSubscription;
use App\Models\User;
use App\Support\CaseVideo;
use App\Support\ProfileCases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Кейси профілю: нормалізація, редагування в адмінці й показ на сторінці.
 * Найцінніший тут — тест збереження з адмінки: кейси лежать у JSON-колонці
 * поряд з іншими даними, і саме там легко затерти чуже.
 */
class ProfileCasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_keeps_media_and_drops_broken_values(): void
    {
        $cases = ProfileCases::normalize([
            [
                'title' => '  Виграли суд  ',
                'result' => 'Рішення на користь клієнта',
                'video' => 'https://youtu.be/abc12345678',
                'image' => 'profiles/cases/photo.jpg',
                'url' => 'javascript:alert(1)',
                'review_id' => '42',
            ],
            ['title' => '', 'result' => ''],
            ['title' => 'Без результату', 'image' => '../../etc/passwd'],
        ]);

        $this->assertCount(2, $cases);
        $this->assertSame('Виграли суд', $cases[0]['title']);
        $this->assertSame('https://youtu.be/abc12345678', $cases[0]['video']);
        $this->assertSame('profiles/cases/photo.jpg', $cases[0]['image']);
        $this->assertArrayNotHasKey('url', $cases[0], 'javascript: не має пройти як посилання');
        $this->assertSame(42, $cases[0]['review_id']);
        $this->assertArrayNotHasKey('image', $cases[1], 'вихід угору по дереву заборонений');
    }

    public function test_video_parser_recognizes_youtube_file_and_other_links(): void
    {
        $this->assertSame('youtube', CaseVideo::parse('https://www.youtube.com/watch?v=abc12345678')['kind']);
        $this->assertSame('youtube', CaseVideo::parse('https://www.youtube.com/shorts/abc12345678')['kind']);
        $this->assertSame('file', CaseVideo::parse('https://site.com/vid/news-1.mp4')['kind']);
        $this->assertSame('link', CaseVideo::parse('https://www.tiktok.com/@user/video/7')['kind']);
        $this->assertNull(CaseVideo::parse('ftp://site.com/a.mp4'));
    }

    public function test_admin_saves_cases_without_losing_other_json_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $profile = Profile::query()->create([
            'name' => 'Тестовий бізнес',
            'slug' => 'testovyi-biznes',
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
            'ai_suggested_data' => [
                'specializations_title' => 'Сфери практики',
                'owner_profile' => ['faq' => [['q' => 'Питання', 'a' => 'Відповідь']]],
            ],
        ]);

        Livewire::actingAs($admin)
            ->test(EditProfile::class, ['record' => $profile->getKey()])
            ->fillForm([
                'owner_cases' => [
                    [
                        'title' => 'Повернули аванс',
                        'task' => 'Підрядник зник із передоплатою',
                        'result' => 'Кошти повернули в повному обсязі',
                        'video' => 'https://youtu.be/abc12345678',
                        'image_url' => 'https://site.com/photo.jpg',
                    ],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $profile->refresh();
        $cases = data_get($profile->ai_suggested_data, 'owner_profile.cases', []);

        $this->assertCount(1, $cases);
        $this->assertSame('Повернули аванс', $cases[0]['title']);
        $this->assertSame('https://site.com/photo.jpg', $cases[0]['image'], 'посилання на фото має лягти в поле image');
        $this->assertSame('https://youtu.be/abc12345678', $cases[0]['video']);

        // Найважливіше: сусідні ключі JSON лишилися недоторканими.
        $this->assertSame('Сфери практики', data_get($profile->ai_suggested_data, 'specializations_title'));
        $this->assertSame('Питання', data_get($profile->ai_suggested_data, 'owner_profile.faq.0.q'));
    }

    public function test_cases_are_shown_only_for_pro_profiles(): void
    {
        $profile = Profile::query()->create([
            'name' => 'Профіль з кейсами',
            'slug' => 'profil-z-keisamy',
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
            'ai_suggested_data' => [
                'owner_profile' => [
                    'cases' => [
                        [
                            'title' => 'Зняли арешт з авто',
                            'result' => 'Автомобіль повернули власнику',
                            'video' => 'https://youtu.be/abc12345678',
                        ],
                        [
                            'title' => 'Відео з власного сайту',
                            'result' => 'Клієнт розповів про результат на камеру',
                            'video' => 'https://site.com/vid/news-1.mp4',
                            'image' => 'https://site.com/poster.jpg',
                        ],
                    ],
                ],
            ],
        ]);

        $this->get(route('profile.show', ['slug' => $profile->slug]))
            ->assertOk()
            ->assertDontSee('Зняли арешт з авто');

        ProSubscription::query()->create([
            'profile_id' => $profile->id,
            'plan' => 'pro',
            'status' => 'active',
            'started_at' => now()->subDay(),
            'ends_at' => now()->addMonths(6),
        ]);
        $profile->update(['is_pro' => true]);

        $this->get(route('profile.show', ['slug' => $profile->slug]))
            ->assertOk()
            ->assertSee('Зняли арешт з авто')
            ->assertSee('youtube-nocookie.com/embed/abc12345678', false)
            // Прямий файл — вбудований плеєр із превʼю-картинкою.
            ->assertSee('<source src="https://site.com/vid/news-1.mp4">', false)
            ->assertSee('poster="https://site.com/poster.jpg"', false);
    }
}
