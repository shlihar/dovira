<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\EmailTemplate;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::query()->updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => bcrypt('password'),
                'role' => 'user',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'admin@dovira.test'],
            [
                'name' => 'DOVIRA Admin',
                'password' => bcrypt('Admin123!'),
                'role' => 'admin',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        $this->seedEmailTemplates();

        $this->call([
            RegionsTableSeeder::class,
            CategoriesSeeder::class,
            CategoryServicesSeeder::class,
            ProfilesSeeder::class,
            ProfileReviewsSeeder::class,
            OfficialRepliesSeeder::class,
            ProSubscriptionsSeeder::class,
        ]);
    }

    private function seedEmailTemplates(): void
    {
        $templates = [
            [
                'key' => 'review.pending',
                'name' => 'Review pending',
                'subject' => 'Ваш відгук на модерації — {{profile_name}}',
                'body_html' => '<p>Вітаємо, {{author_name}}.</p><p>Ваш відгук для профілю <b>{{profile_name}}</b> отримано та передано на модерацію.</p>',
            ],
            [
                'key' => 'review.published',
                'name' => 'Review published',
                'subject' => 'Відгук опубліковано — {{profile_name}}',
                'body_html' => '<p>Вітаємо, {{author_name}}.</p><p>Ваш відгук для профілю <b>{{profile_name}}</b> успішно опубліковано.</p>',
            ],
            [
                'key' => 'review.rejected',
                'name' => 'Review rejected',
                'subject' => 'Відгук відхилено — {{profile_name}}',
                'body_html' => '<p>Вітаємо, {{author_name}}.</p><p>Ваш відгук для профілю <b>{{profile_name}}</b> було відхилено модератором.</p>',
            ],
            [
                'key' => 'review.under_review',
                'name' => 'Review under review',
                'subject' => 'Відгук потребує уточнення — {{profile_name}}',
                'body_html' => '<p>Вітаємо, {{author_name}}.</p><p>Ваш відгук для профілю <b>{{profile_name}}</b> потребує додаткової перевірки.</p>',
            ],
            [
                'key' => 'review.hidden',
                'name' => 'Review hidden',
                'subject' => 'Відгук приховано — {{profile_name}}',
                'body_html' => '<p>Вітаємо, {{author_name}}.</p><p>Ваш відгук для профілю <b>{{profile_name}}</b> тимчасово приховано.</p>',
            ],
            [
                'key' => 'review.reply',
                'name' => 'Official reply',
                'subject' => 'Вам відповіли на відгук — {{profile_name}}',
                'body_html' => '<p>Вітаємо, {{author_name}}.</p><p>Профіль <b>{{profile_name}}</b> залишив офіційну відповідь:</p><blockquote>{{reply_body}}</blockquote>',
            ],
        ];

        foreach ($templates as $template) {
            EmailTemplate::query()->updateOrCreate(
                ['key' => $template['key']],
                [
                    'name' => $template['name'],
                    'subject' => $template['subject'],
                    'body_html' => $template['body_html'],
                    'body_text' => null,
                    'is_active' => true,
                ]
            );
        }
    }
}
