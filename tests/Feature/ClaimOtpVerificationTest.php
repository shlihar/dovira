<?php

namespace Tests\Feature;

use App\Mail\ProfileClaimCodeMail;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ClaimOtpVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_verifies_profile_via_email_otp(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $profile = Profile::create([
            'name' => 'Адвокат Тест',
            'status' => 'active',
            'email' => 'office@example.com',
            'is_published' => true,
            'show_in_catalog' => true,
        ]);

        $this->actingAs($user);

        // Крок 0: доступні канали (email є).
        $this->postJson(route('pro.account.claims.request-code'), ['profile_id' => $profile->id])
            ->assertOk()
            ->assertJsonPath('channels.0.channel', 'email');

        // Крок 1: надіслати код на email.
        $this->postJson(route('pro.account.claims.request-code'), [
            'profile_id' => $profile->id,
            'channel' => 'email',
        ])->assertOk()->assertJsonPath('ok', true);

        $code = null;
        Mail::assertSent(ProfileClaimCodeMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });
        $this->assertNotNull($code);

        // Крок 2: ввести код → авто-апрув.
        $this->postJson(route('pro.account.claims.verify-code'), [
            'profile_id' => $profile->id,
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            // Після безкоштовного підтвердження ведемо В КАБІНЕТ (спершу
            // цінність — статистика переглядів, і лише потім рішення про PRO).
            ->assertJsonPath('redirect', route('pro.account', ['tab' => 'overview', 'profile' => $profile->id]));

        $profile->refresh();
        $this->assertSame($user->id, $profile->owner_user_id);
        $this->assertTrue((bool) $profile->is_owner_verified);
        $this->assertDatabaseHas('profile_claims', [
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'status' => 'approved',
        ]);
    }

    public function test_profile_claim_channels_expose_full_email_and_no_sms_while_channel_is_off(): void
    {
        // SMS-канал вимкнено прапорцем у ClaimVerificationService (рішення
        // 2026-08-25): підтвердження лише email або через підтримку. Контакт
        // показуємо ПОВНІСТЮ — власнику треба бачити, куди піде код.
        config(['services.sms.turbosms.token' => 'test-token']);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $profile = Profile::create([
            'name' => 'Клініка Контакт',
            'status' => 'active',
            'email' => 'owner@example.com',
            'phone' => '(050) 111 22 33, (096) 444 55 66',
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson(route('pro.account.claims.request-code'), ['profile_id' => $profile->id]);

        $response
            ->assertOk()
            ->assertJsonPath('channels.0.channel', 'email')
            ->assertJsonPath('channels.0.masked', 'owner@example.com')
            ->assertJsonCount(1, 'channels')
            ->assertJsonPath('manual', false);
    }

    public function test_owner_can_request_code_for_selected_phone_from_multiple_numbers(): void
    {
        config([
            'services.sms.turbosms.token' => 'test-token',
            'services.sms.turbosms.sender' => 'Dovira',
        ]);

        Http::fake([
            'https://api.turbosms.ua/message/send.json' => Http::response([
                'response_code' => 0,
                'response_result' => [
                    ['response_code' => 0],
                ],
            ]),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $profile = Profile::create([
            'name' => 'Клініка Контакт',
            'status' => 'active',
            'phone' => '(050) 111 22 33, (096) 444 55 66',
        ]);

        $channels = $this
            ->actingAs($user)
            ->postJson(route('pro.account.claims.request-code'), ['profile_id' => $profile->id])
            ->json('channels');

        $phones = collect($channels)->where('channel', 'phone')->values();

        // Поки SMS-канал вимкнено прапорцем (ClaimVerificationService::
        // SMS_CHANNEL_ENABLED), телефонів у каналах немає — тест автоматично
        // повернеться в дію, щойно канал увімкнуть назад.
        if ($phones->count() < 2) {
            $this->markTestSkipped('SMS-канал підтвердження вимкнено — перевіряти вибір номера нічого.');
        }

        $secondPhone = $phones->get(1);

        $this
            ->actingAs($user)
            ->postJson(route('pro.account.claims.request-code'), [
                'profile_id' => $profile->id,
                'channel' => 'phone',
                'destination_key' => $secondPhone['destination_key'],
            ])
            ->assertOk()
            ->assertJsonPath('masked', '••• ••• 66');

        Http::assertSent(fn ($request): bool => $request->data()['recipients'] === ['380964445566']);

        $this->assertDatabaseHas('profile_claim_verifications', [
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'channel' => 'phone',
            'destination' => '380964445566',
        ]);
    }

    public function test_wrong_code_is_rejected(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $profile = Profile::create([
            'name' => 'Адвокат Два',
            'status' => 'active',
            'email' => 'two@example.com',
        ]);

        $this->actingAs($user);

        $this->postJson(route('pro.account.claims.request-code'), [
            'profile_id' => $profile->id,
            'channel' => 'email',
        ])->assertOk();

        $this->postJson(route('pro.account.claims.verify-code'), [
            'profile_id' => $profile->id,
            'code' => '000000',
        ])->assertStatus(422)->assertJsonPath('ok', false);

        $profile->refresh();
        $this->assertNull($profile->owner_user_id);
    }
}
