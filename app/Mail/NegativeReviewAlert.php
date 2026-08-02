<?php

namespace App\Mail;

use App\Models\Profile;
use App\Models\ProfileReview;
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

    public function envelope(): Envelope
    {
        $subject = $this->forOwner
            ? 'Новий негативний відгук про ' . $this->profile->name
            : 'Про ваш бізнес залишили негативний відгук на Dovira';

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
            ],
        );
    }
}
