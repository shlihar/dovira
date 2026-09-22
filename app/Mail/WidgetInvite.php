<?php

namespace App\Mail;

use App\Models\Profile;
use App\Support\ProPricing;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Лист заявленим власникам, у яких є сайт, але ще немає нашого віджета
 * (dovira:outreach:widget-invite). Дає ГОТОВИЙ код під їхній профіль —
 * скопіювати й вставити, — і нагадує про знижку 20% за розміщений бейдж.
 *
 * Для нас кожен розміщений віджет — тематичне посилання з реального
 * бізнес-сайту, тобто найцінніший тип беклінка для домену.
 */
class WidgetInvite extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Profile $profile,
        public int $reviews,
        public float $rating,
        public string $accountUrl,
    ) {
    }

    public static function subjectFor(Profile $profile): string
    {
        $host = preg_replace('#^www\.#', '', (string) parse_url((string) $profile->website, PHP_URL_HOST));

        return "Готовий код віджета для {$host} — і знижка 20%";
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::subjectFor($this->profile));
    }

    public function content(): Content
    {
        $slug = $this->profile->slug;
        $base = 'https://mydovira.com/widget/' . $slug;

        return new Content(
            view: 'emails.widget-invite',
            with: [
                'profileName' => $this->profile->name,
                'siteHost' => preg_replace('#^www\.#', '', (string) parse_url((string) $this->profile->website, PHP_URL_HOST)),
                'reviews' => $this->reviews,
                'ratingLabel' => number_format($this->rating, 1, ',', ''),
                'hasRating' => $this->reviews >= 20 && $this->rating > 0,
                'floatSnippet' => '<script src="' . $base . '/float.js" data-position="bottom-right" async></script>',
                'reviewsSnippet' => '<script src="' . $base . '/reviews.js" data-layout="carousel" data-min-rating="4" async></script>',
                'accountUrl' => $this->accountUrl,
                'profileUrl' => 'https://mydovira.com/profiles/' . $slug,
                'discountLine' => ProPricing::formatDisplay(ProPricing::DISPLAY_CURRENT) . ' → '
                    . ProPricing::formatDisplay((int) round(ProPricing::DISPLAY_CURRENT * 0.8)),
            ],
        );
    }
}
