<?php

namespace App\Http\Controllers;

use App\Models\ProfileReview;
use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        $user = $request->user();
        $tab = (string) $request->query('tab', 'dashboard');
        $allowedTabs = ['dashboard', 'profile', 'reviews', 'notifications', 'saved', 'settings'];
        if (!in_array($tab, $allowedTabs, true)) {
            $tab = 'dashboard';
        }

        $reviews = ProfileReview::query()
            ->with(['profile:id,name,slug', 'officialReply:id,profile_review_id,body,created_at'])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate(12, ['*'], 'reviews_page')
            ->withQueryString();

        $allReviewsCount = ProfileReview::query()->where('user_id', $user->id)->count();
        $pendingReviewsCount = ProfileReview::query()->where('user_id', $user->id)->where('status', 'pending')->count();
        $repliedCount = ProfileReview::query()
            ->where('user_id', $user->id)
            ->whereHas('officialReply')
            ->count();
        $savedProfilesCount = 5;

        $notifications = $reviews->getCollection()
            ->flatMap(function (ProfileReview $review) {
                $items = [];
                $profileName = $review->profile?->name ?? 'профіль';

                $statusTitle = match ($review->status) {
                    'published' => 'Ваш відгук опубліковано',
                    'pending' => 'Ваш відгук на модерації',
                    'rejected' => 'Ваш відгук потребує уточнення',
                    default => 'Статус відгуку змінено',
                };

                $items[] = [
                    'type' => 'status',
                    'title' => $statusTitle,
                    'text' => 'Відгук про ' . $profileName,
                    'date' => $review->updated_at ?? $review->created_at,
                ];

                if ($review->officialReply) {
                    $items[] = [
                        'type' => 'reply',
                        'title' => 'Вам відповіли на відгук',
                        'text' => 'Офіційна відповідь від профілю ' . $profileName,
                        'date' => $review->officialReply->created_at ?? $review->updated_at ?? $review->created_at,
                    ];
                }

                return $items;
            })
            ->sortByDesc('date')
            ->take(20)
            ->values();

        return view('profile.edit', compact(
            'user',
            'tab',
            'reviews',
            'notifications',
            'allReviewsCount',
            'pendingReviewsCount',
            'repliedCount',
            'savedProfilesCount'
        ));
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $validated['email_notifications_enabled'] = $request->boolean('email_notifications_enabled');

        $request->user()->fill($validated);

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit', ['tab' => 'settings'])->with('status', 'profile-updated');
    }

    public function updateReview(Request $request, ProfileReview $review): RedirectResponse
    {
        abort_if((int) $review->user_id !== (int) $request->user()->id, 403);

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            // Текст необов'язковий — відгук може бути лише оцінкою.
            'body' => ['nullable', 'string', 'max:3000'],
        ]);

        $review->update([
            'rating' => (int) $validated['rating'],
            'body' => trim((string) ($validated['body'] ?? '')),
            'status' => 'pending',
        ]);

        return Redirect::route('profile.edit', ['tab' => 'reviews'])
            ->with('status', 'review-updated');
    }

    public function withdrawReview(Request $request, ProfileReview $review): RedirectResponse
    {
        abort_if((int) $review->user_id !== (int) $request->user()->id, 403);

        if ($review->status === 'published') {
            return Redirect::route('profile.edit', ['tab' => 'reviews'])
                ->withErrors(['review' => 'Опублікований відгук не можна відкликати.']);
        }

        $review->update([
            'status' => 'withdrawn',
        ]);

        return Redirect::route('profile.edit', ['tab' => 'reviews'])
            ->with('status', 'review-withdrawn');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
