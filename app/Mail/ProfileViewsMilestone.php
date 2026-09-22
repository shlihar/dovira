<?php

namespace App\Mail;

use App\Models\Profile;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Тригер-лист про досягнутий поріг переглядів профілю (100, 200, …).
 *   $forOwner = true  → власнику без PRO («стільки людей вас дивляться —
 *                        увімкніть PRO, щоб бачити, хто і що робить»)
 *   $forOwner = false → холодний аутріч на незаявлений профіль
 *                        («вашу сторінку дивляться — заберіть її під контроль»)
 */
class ProfileViewsMilestone extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Profile $profile,
        public int $views,
        public bool $forOwner,
        public string $ctaUrl,
        public ?string $unsubscribeUrl = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $subject = $this->forOwner
            ? 'Ваш профіль на Dovira побачили вже ' . $this->views . ' разів'
            : 'Ваш профіль на Dovira побачили ' . $this->views . ' разів';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.views-milestone',
            with: [
                'profileName' => $this->profile->name,
                'views' => $this->views,
                'forOwner' => $this->forOwner,
                'ctaUrl' => $this->ctaUrl,
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ],
        );
    }
}
