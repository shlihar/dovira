<?php

namespace Tests\Feature;

use App\Mail\ProfileClaimCodeMail;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * OTP-підтвердження прав через email: єдиний автоматичний канал після
 * тимчасового вимкнення SMS (2026-08-25). Email показуємо повністю.
 */
class ClaimEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makeProfile(array $overrides = []): Profile
    {
        return Profile::query()->create(array_merge([
            'name' => 'Клініка Здоровʼя',
            'slug' => 'klinika-zdorovya',
            'type' => 'company',
            'status' => 'active',
            'is_published' => true,
            'show_in_catalog' => true,
            'email' => 'owner@clinic.example',
            'phone' => '+380 67 123 45 67',
        ], $overrides));
    }

    public function test_verify_page_shows_full_email_and_no_sms_channel(): void
    {
        $user = $this->makeUser();
        $profile = $this->makeProfile();

        $response = $this->actingAs($user)->get(route('pro.account.claims.verify', $profile));

        $response->assertOk();
        // Email повністю, без маскування.
        $response->assertSee('owner@clinic.example');
        $response->assertSee('Отримати код на email');
        // SMS-канал тимчасово вимкнено — кнопки бути не повинно.
        $response->assertDontSee('Отримати SMS-код');
        // Фолбек через підтримку лишається.
        $response->assertSee('Підтвердити через підтримку');
    }

    public function test_email_code_request_and_verify_grants_ownership(): void
    {
        Mail::fake();

        $user = $this->makeUser();
        $profile = $this->makeProfile();

        $request = $this->actingAs($user)->postJson(route('pro.account.claims.request-code'), [
            'profile_id' => $profile->id,
            'channel' => 'email',
        ]);

        $request->assertOk();
        $request->assertJson(['ok' => true, 'channel' => 'email', 'masked' => 'owner@clinic.example']);

        $sentCode = null;
        Mail::assertSent(ProfileClaimCodeMail::class, function (ProfileClaimCodeMail $mail) use (&$sentCode): bool {
            $sentCode = $mail->code;

            return $mail->hasTo('owner@clinic.example');
        });
        $this->assertNotNull($sentCode);

        $verify = $this->actingAs($user)->postJson(route('pro.account.claims.verify-code'), [
            'profile_id' => $profile->id,
            'code' => $sentCode,
        ]);

        $verify->assertOk();
        $verify->assertJson(['ok' => true]);

        $profile->refresh();
        $this->assertSame($user->id, (int) $profile->owner_user_id);
        $this->assertTrue((bool) $profile->is_owner_verified);
    }

    public function test_wrong_code_is_rejected(): void
    {
        Mail::fake();

        $user = $this->makeUser();
        $profile = $this->makeProfile();

        $this->actingAs($user)->postJson(route('pro.account.claims.request-code'), [
            'profile_id' => $profile->id,
            'channel' => 'email',
        ])->assertOk();

        $verify = $this->actingAs($user)->postJson(route('pro.account.claims.verify-code'), [
            'profile_id' => $profile->id,
            'code' => '000000',
        ]);

        $verify->assertStatus(422);
        $this->assertNull($profile->refresh()->owner_user_id);
    }

    public function test_phone_channel_is_rejected_while_sms_disabled(): void
    {
        Mail::fake();

        $user = $this->makeUser();
        $profile = $this->makeProfile();

        $request = $this->actingAs($user)->postJson(route('pro.account.claims.request-code'), [
            'profile_id' => $profile->id,
            'channel' => 'phone',
        ]);

        $request->assertStatus(422);
        Mail::assertNothingSent();
    }
}
