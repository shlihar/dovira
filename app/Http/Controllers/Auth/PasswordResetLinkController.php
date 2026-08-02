<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Attempt to send the reset link (only actually sends for real accounts).
        Password::sendResetLink(
            $request->only('email')
        );

        // Always respond with the same neutral message, regardless of whether the
        // email belongs to a real account or was throttled. Returning a different
        // response for "user not found" would let attackers enumerate which
        // emails are registered.
        return back()->with('status', __(Password::RESET_LINK_SENT));
    }
}
