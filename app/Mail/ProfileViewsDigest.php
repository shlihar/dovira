<?php

namespace App\Mail;

use App\Models\Profile;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Періодичний дайджест «Вас шукали» для власника профілю без PRO: скільки людей
 * переглянули сторінку за місяць, скільки тиснули контакт, скільки нових відгуків
 * — і заклик увімкнути PRO, щоб бачити деталі й розблокувати контакти.
 *
 * @param  array{views:int, unique:int, contact_clicks:int, website_clicks:int, reviews_count:int, rating_avg:float}  $stats
 */
class ProfileViewsDigest extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Profile $profile,
        public string $periodLabel,
        public array $stats,
        public string $ctaUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Dovira · звіт за ' . $this->periodLabel . ': вашу сторінку шукають',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.views-digest',
            with: [
                'profileName' => $this->profile->name,
                'periodLabel' => $this->periodLabel,
                'stats' => $this->stats,
                'ctaUrl' => $this->ctaUrl,
            ],
        );
    }
}
