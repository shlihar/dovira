<?php

namespace App\Mail;

use App\Models\Profile;
use App\Models\ProfileEvent;
use App\Models\ProfileReview;
use App\Services\ProfileAnalyticsService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Тригер-лист бізнесу про негативний відгук.
 *   $forOwner = true  → власнику заявленого профілю («відповідайте в кабінеті»)
 *   $forOwner = false → холодний аутріч на незаявлений профіль
 *                       («підтвердіть профіль безкоштовно, щоб відповісти»)
 */
class NegativeReviewAlert extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Profile $profile,
        public ProfileReview $review,
        public bool $forOwner,
        public string $ctaUrl,
        public ?string $unsubscribeUrl = null,
    ) {
    }

    /**
     * Перегляди профілю за 30 днів — головний аргумент листа («цей відгук уже
     * бачать»). Маленька цифра продає проти нас, тому нижче порогу не показуємо.
     */
    private const VIEWS_MIN_TO_SHOW = 25;

    public function envelope(): Envelope
    {
        $subject = $this->forOwner
            ? 'Новий негативний відгук про ' . $this->profile->name . ' — ви можете відповісти'
            : 'Негативний відгук про ' . $this->profile->name . ' — його вже бачать клієнти';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.negative-review-alert',
            with: [
                'profileName' => $this->profile->name,
                'rating' => (float) $this->review->rating,
                'reviewBody' => \Illuminate\Support\Str::limit((string) $this->review->body, 400),
                'authorName' => (string) ($this->review->author_name ?: 'Клієнт'),
                'forOwner' => $this->forOwner,
                'ctaUrl' => $this->ctaUrl,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'viewsLast30' => $this->viewsLast30Days(),
            ],
        );
    }

    private function viewsLast30Days(): ?int
    {
        $views = (int) ProfileEvent::query()
            ->where('profile_id', $this->profile->id)
            ->where('event_type', ProfileAnalyticsService::EVENT_PROFILE_VIEW)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        return $views >= self::VIEWS_MIN_TO_SHOW ? $views : null;
    }
}
