<?php

namespace App\Services;

use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\PlatformNotification;
use App\Models\ProfileReview;
use Illuminate\Support\Facades\Mail;

class ReviewNotificationService
{
    public function onStatusChanged(ProfileReview $review, string $newStatus): void
    {
        $map = [
            'pending' => [
                'template' => 'review.pending',
                'type' => 'review_pending',
                'title' => 'Ваш відгук на модерації',
                'body' => 'Ми отримали ваш відгук і передали його на модерацію.',
            ],
            'published' => [
                'template' => 'review.published',
                'type' => 'review_published',
                'title' => 'Ваш відгук опубліковано',
                'body' => 'Відгук успішно опубліковано на платформі DOVIRA.',
            ],
            'rejected' => [
                'template' => 'review.rejected',
                'type' => 'review_rejected',
                'title' => 'Відгук відхилено',
                'body' => 'Ваш відгук відхилено модератором. Перевірте деталі в кабінеті.',
            ],
            'under_review' => [
                'template' => 'review.under_review',
                'type' => 'review_under_review',
                'title' => 'Відгук потребує уточнення',
                'body' => 'Модератор запросив додаткову перевірку або уточнення.',
            ],
            'hidden' => [
                'template' => 'review.hidden',
                'type' => 'review_hidden',
                'title' => 'Відгук приховано',
                'body' => 'Ваш відгук тимчасово приховано для додаткової перевірки.',
            ],
        ];

        if (!isset($map[$newStatus])) {
            return;
        }

        $entry = $map[$newStatus];
        $this->createPlatformNotification($review, $entry['type'], $entry['title'], $entry['body']);
        $this->sendTemplatedEmail($review, $entry['template'], [
            'author_name' => $review->author_name,
            'profile_name' => $review->profile?->name ?? 'профіль',
            'review_body' => $review->body,
            'status' => $newStatus,
        ]);
    }

    public function onOfficialReply(ProfileReview $review, string $replyBody): void
    {
        $this->createPlatformNotification(
            $review,
            'review_reply',
            'Вам відповіли на відгук',
            'Профіль залишив офіційну відповідь на ваш відгук.'
        );

        $this->sendTemplatedEmail($review, 'review.reply', [
            'author_name' => $review->author_name,
            'profile_name' => $review->profile?->name ?? 'профіль',
            'reply_body' => $replyBody,
        ]);
    }

    protected function createPlatformNotification(ProfileReview $review, string $type, string $title, string $body): void
    {
        if (!$review->user_id) {
            return;
        }

        PlatformNotification::query()->create([
            'user_id' => $review->user_id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'meta' => [
                'review_id' => $review->id,
                'profile_id' => $review->profile_id,
            ],
        ]);
    }

    protected function sendTemplatedEmail(ProfileReview $review, string $templateKey, array $vars): void
    {
        $toEmail = $review->author?->email ?: $review->author_email;

        if (!$toEmail) {
            return;
        }

        $template = EmailTemplate::query()->where('key', $templateKey)->where('is_active', true)->first();

        if (!$template) {
            return;
        }

        $subject = $this->interpolate($template->subject, $vars);
        $html = $this->interpolate($template->body_html, $vars);

        $log = EmailLog::query()->create([
            'user_id' => $review->user_id,
            'email_template_id' => $template->id,
            'to_email' => $toEmail,
            'subject' => $subject,
            'status' => 'queued',
        ]);

        try {
            Mail::html($html, function ($message) use ($toEmail, $subject): void {
                $message->to($toEmail)->subject($subject);
            });

            $log->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 65000),
            ]);
        }
    }

    protected function interpolate(string $template, array $vars): string
    {
        $result = $template;

        foreach ($vars as $key => $value) {
            $result = str_replace('{{' . $key . '}}', (string) $value, $result);
        }

        return $result;
    }
}
