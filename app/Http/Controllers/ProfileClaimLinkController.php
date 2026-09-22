<?php

namespace App\Http\Controllers;

use App\Models\Profile;
use App\Models\ProfileClaim;
use App\Models\User;
use App\Services\ProfileClaimReviewService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Магічне посилання-claim: у листі аутрічу адвокату йде підписаний лінк на
 * ЙОГО пошту профілю. Клік = доказ володіння (та сама логіка, що OTP), тож
 * профіль підтверджується без реєстрації й без коду — в один клік.
 *
 * Два кроки навмисно: GET показує сторінку-підтвердження (щоб прев'ю-боти
 * пошти, які «клікають» лінки, НЕ забрали профіль), а сам claim робить POST.
 */
class ProfileClaimLinkController extends Controller
{
    /** Сторінка-підтвердження (нічого не змінює). Захищена signed middleware. */
    public function show(Profile $profile): View
    {
        $email = trim((string) $profile->email);

        // Профіль уже має підтвердженого власника.
        if ($profile->owner_user_id && $profile->is_owner_verified) {
            return view('static.claim-link', [
                'profile' => $profile,
                'state' => Auth::id() === (int) $profile->owner_user_id ? 'yours' : 'taken',
                'confirmUrl' => null,
            ]);
        }

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return view('static.claim-link', ['profile' => $profile, 'state' => 'no-email', 'confirmUrl' => null]);
        }

        return view('static.claim-link', [
            'profile' => $profile,
            'state' => 'confirm',
            // Email не маскуємо: лист і так прийшов на цю адресу, читач її знає.
            'maskedEmail' => $email,
            'confirmUrl' => URL::temporarySignedRoute('profile.claim-link.confirm', now()->addDays(30), ['profile' => $profile->id]),
        ]);
    }

    /** Виконати підтвердження: створити/знайти юзера, залогінити, надати права. */
    public function confirm(Profile $profile, ProfileClaimReviewService $claims): RedirectResponse
    {
        // Уже заявлений іншим — не переприв'язуємо.
        if ($profile->owner_user_id && $profile->is_owner_verified && Auth::id() !== (int) $profile->owner_user_id) {
            return redirect()->route('profile.show', ['slug' => $profile->slug])
                ->with('status', 'claim-link-taken');
        }

        $email = mb_strtolower(trim((string) $profile->email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(404);
        }

        // Юзер за поштою профілю: існує — беремо, ні — створюємо passwordless
        // (пошта вважається підтвердженою, бо людина клікнула лінк саме з неї).
        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            $user = new User();
            $user->email = $email;
            $user->name = (string) $profile->name;
            $user->password = Hash::make(Str::random(40));
            $user->auth_provider = 'claim_link';
            $user->email_notifications_enabled = true;
            $user->role = 'user';
            $user->status = 'active';
        }
        if (blank($user->email_verified_at)) {
            $user->email_verified_at = now();
        }
        $user->save();

        // Уже власник цього профілю — просто логінимо.
        if (! ($profile->owner_user_id === $user->id && $profile->is_owner_verified)) {
            $claim = ProfileClaim::query()->firstOrNew([
                'profile_id' => $profile->id,
                'user_id' => $user->id,
            ]);
            $claim->status = 'pending';
            $claim->save();

            rescue(fn () => $claims->approveViaVerification($claim, 'email_link'));
        }

        Auth::login($user, true);

        return redirect()->route('pro.account.billing.pay', ['profile' => $profile->id])
            ->with('status', 'profile-claimed');
    }
}
