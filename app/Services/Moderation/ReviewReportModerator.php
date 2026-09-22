<?php

declare(strict_types=1);

namespace App\Services\Moderation;

use App\Models\ReviewReport;
use App\Services\ProProfileNotificationService;

/**
 * Єдина логіка рішення по скарзі на відгук — щоб і relation-manager у профілі,
 * і окремий розділ «Скарги на відгуки» діяли однаково.
 *
 * resolve = задовольнити (зняти відгук з сайту), reject = відхилити (повернути).
 */
class ReviewReportModerator
{
    public function __construct(private readonly ProProfileNotificationService $notifications)
    {
    }

    public function resolve(ReviewReport $report, ?string $note, ?int $moderatorId): void
    {
        $this->close($report, 'resolved', $note, $moderatorId);

        $review = $report->review;
        if ($review && $review->status !== 'rejected') {
            $review->update(['status' => 'rejected']);
        }

        $this->notifyOwner(
            $report,
            'Скаргу задоволено — відгук знято',
            'Модерація розглянула скаргу і зняла відгук з публічної сторінки. Рейтинг перераховано.'
        );
    }

    public function reject(ReviewReport $report, ?string $note, ?int $moderatorId): void
    {
        $this->close($report, 'rejected', $note, $moderatorId);

        $review = $report->review;
        if ($review && $review->status === 'hidden') {
            $review->update(['status' => 'published']);
        }

        $this->notifyOwner(
            $report,
            'Скаргу відхилено — відгук повернено',
            'Модерація розглянула скаргу і не знайшла порушень. Відгук знову опубліковано на сторінці профілю.'
        );
    }

    /**
     * Закрити скаргу вердиктом і «підтягнути» інші відкриті скарги на той самий
     * відгук — щоб відгук не лишався з відкритою скаргою після рішення.
     */
    private function close(ReviewReport $report, string $status, ?string $note, ?int $moderatorId): void
    {
        $note = trim((string) $note);
        $report->update([
            'status' => $status,
            'moderator_note' => $note !== '' ? $note : $report->moderator_note,
            'resolved_by_user_id' => $moderatorId,
            'resolved_at' => now(),
        ]);

        ReviewReport::query()
            ->where('profile_review_id', $report->profile_review_id)
            ->whereKeyNot($report->id)
            ->whereIn('status', ['open', 'in_review'])
            ->update([
                'status' => $status,
                'resolved_by_user_id' => $moderatorId,
                'resolved_at' => now(),
            ]);
    }

    private function notifyOwner(ReviewReport $report, string $title, string $body): void
    {
        $profile = $report->review?->profile;
        if (! $profile || ! $profile->owner_user_id) {
            return;
        }

        $this->notifications->create(
            $profile,
            'review_report_resolved',
            $title,
            $body,
            [
                'severity' => 'info',
                'action_url' => route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id]),
            ]
        );
    }
}
