<?php

namespace Tests\Feature;

use App\Mail\ProNurtureEmail;
use App\Models\Profile;
use App\Models\User;
use App\Services\Notifications\ProNurtureMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProNurtureMailerTest extends TestCase
{
    use RefreshDatabase;

    public function test_welcome_email_uses_green_dossier_widget_offer(): void
    {
        Mail::fake();

        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $profile = Profile::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Green Legal',
            'slug' => 'green-legal',
            'type' => 'company',
            'status' => 'active',
            'dossier_verdict' => 'green',
        ]);

        $sent = app(ProNurtureMailer::class)->sendByKey($profile, 'welcome');

        $this->assertTrue($sent);

        Mail::assertSent(ProNurtureEmail::class, function (ProNurtureEmail $mail): bool {
            return $mail->hasTo('owner@example.com')
                && $mail->step['subject'] === 'Гарний рейтинг на Dovira — використайте його для звернень'
                && $mail->step['cta_label'] === 'Оформити підписку зі знижкою'
                && str_contains(implode("\n", $mail->step['body']), 'отримайте -20% на підписку');
        });
    }
}
