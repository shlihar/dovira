<?php

namespace App\Observers;

use App\Jobs\RefreshHomePageCache;
use App\Jobs\RefreshProfileReviewAiAnalysis;
use App\Models\ProfileReview;
use App\Services\Notifications\TelegramAdminNotifier;
use App\Services\ProProfileNotificationService;
use App\Services\ProfileReviewStatsService;
use App\Services\ReviewNotificationService;
use Illuminate\Support\Facades\DB;

class ProfileReviewObserver
{
    public function created(ProfileReview $review): void
    {
        app(ProfileReviewStatsService::class)->recalculateForProfileId((int) $review->profile_id);
        RefreshProfileReviewAiAnalysis::dispatch((int) $review->profile_id)->afterCommit();
        $this->refreshHomePageAfterCommit();

        $review->loadMissing('profile');
        if (! $review->profile) {
            return;
        }

        $notificationService = app(ProProfileNotificationService::class);
        $actionUrl = route('pro.account', ['tab' => 'reviews', 'profile' => $review->profile_id]);

        $notificationService->create(
            $review->profile,
            ((float) $review->rating <= 2) ? 'pro_review_negative' : 'pro_review_new',
            ((float) $review->rating <= 2) ? 'Новий негативний відгук' : 'Надійшов новий відгук',
            ((float) $review->rating <= 2)
                ? 'Клієнт залишив негативний відгук. Важливо швидко перевірити деталі та відповісти.'
                : 'По профілю надійшов новий відгук. Перевірте його у вкладці відгуків.',
            [
                'severity' => ((float) $review->rating <= 2) ? 'danger' : 'info',
                'action_url' => $actionUrl,
                'review_id' => $review->id,
            ]
        );

        // Адмін-сповіщення в Telegram лише для справжніх відгуків користувачів —
        // імпортовані (external_source_type) сюди не доходять (createQuietly),
        // а решта фільтрується за відсутністю зовнішнього джерела.
        if (blank($review->external_source_type)) {
            app(TelegramAdminNotifier::class)->newReview($review);
        }

        // Email-тригер бізнесу про негативний відгук (двигун конверсії PRO).
        // Після коміту, щоб відправка не блокувала й не ламала вставку відгуку.
        DB::afterCommit(function () use ($review): void {
            rescue(fn () => app(\App\Services\Notifications\NegativeReviewAlertMailer::class)->handle($review));
        });
    }

    public function updated(ProfileReview $review): void
    {
        if ($review->wasChanged('status')) {
            app(ReviewNotificationService::class)->onStatusChanged($review, (string) $review->status);
        }

        if ($review->wasChanged(['status', 'rating', 'profile_id'])) {
            $currentProfileId = (int) $review->profile_id;
            app(ProfileReviewStatsService::class)->recalculateForProfileId($currentProfileId);
            RefreshProfileReviewAiAnalysis::dispatch($currentProfileId)->afterCommit();

            $originalProfileId = (int) ($review->getOriginal('profile_id') ?? 0);
            if ($originalProfileId > 0 && $originalProfileId !== $currentProfileId) {
                app(ProfileReviewStatsService::class)->recalculateForProfileId($originalProfileId);
                RefreshProfileReviewAiAnalysis::dispatch($originalProfileId)->afterCommit();
            }
        }

        if ($review->wasChanged([
            'status',
            'rating',
            'profile_id',
            'published_at',
            'body',
            'author_name',
            'external_review_author_avatar_url',
        ])) {
            $this->refreshHomePageAfterCommit();
        }
    }

    public function deleted(ProfileReview $review): void
    {
        app(ProfileReviewStatsService::class)->recalculateForProfileId((int) $review->profile_id);
        RefreshProfileReviewAiAnalysis::dispatch((int) $review->profile_id)->afterCommit();
        $this->refreshHomePageAfterCommit();
    }

    private function refreshHomePageAfterCommit(): void
    {
        DB::afterCommit(function (): void {
            RefreshHomePageCache::dispatch()->afterCommit();
        });
    }
}
