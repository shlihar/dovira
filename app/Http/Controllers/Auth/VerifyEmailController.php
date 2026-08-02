<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return $this->postVerifyRedirect($request);
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        return $this->postVerifyRedirect($request);
    }

    /**
     * Куди вести після підтвердження email. Якщо власник заходив за посиланням
     * на конкретний профіль (ProAccountController зберіг ціль у сесії) —
     * повертаємо рівно на підтвердження прав цього профілю, щоб він не шукав
     * його заново. Інакше — звичайний intended-фолбек.
     */
    private function postVerifyRedirect(EmailVerificationRequest $request): RedirectResponse
    {
        $claimProfileId = (int) $request->session()->pull('claim_intent_profile');

        if ($claimProfileId > 0) {
            return redirect()->route('pro.account', [
                'tab' => 'claims',
                'claim_profile' => $claimProfileId,
            ]);
        }

        return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
    }
}
