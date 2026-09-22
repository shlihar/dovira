<?php

namespace App\Mail;

use App\Models\Profile;
use App\Support\ProPricing;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Аутріч великим незаявленим профілям (dovira:outreach:top-profiles).
 * Кут: ЇХНІ власні цифри з нашого каталогу (відгуки, рейтинг, місце в
 * категорії у місті) + підтвердження в один клік магічним посиланням.
 * Жодних тверджень, яких не знаємо напевно (без «негатив без відповіді»,
 * без переглядів — уроки скарги «Мережі права», 14.09.2026).
 *
 * @param  array{reviews:int, rating:float, category:?string, city:?string, rank:?int, total:?int}  $facts
 * @param  'a'|'b'  $variant  A/B-тема; тіло однакове.
 */
class TopProfileOutreach extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Profile $profile,
        public array $facts,
        public string $variant,
        public string $claimUrl,
        public string $profileUrl,
        public string $unsubscribeUrl,
    ) {
    }

    /** Теми обох варіантів — по них дедуп у email_logs. */
    public static function subjectFor(Profile $profile, int $reviews, string $variant): string
    {
        return $variant === 'b'
            ? "Хто керує сторінкою «{$profile->name}» на DOVIRA?"
            : "{$profile->name}: {$reviews} відгуків на DOVIRA — а сторінкою ніхто не керує";
    }

    public function envelope(): Envelope
    {
        $fromAddress = trim((string) config('outreach.from.address'));
        $replyToAddress = trim((string) config('outreach.reply_to.address'));

        return new Envelope(
            subject: self::subjectFor($this->profile, (int) $this->facts['reviews'], $this->variant),
            from: $fromAddress !== '' ? new Address($fromAddress, (string) config('outreach.from.name', 'Dovira')) : null,
            replyTo: $replyToAddress !== '' ? [new Address($replyToAddress, (string) config('outreach.reply_to.name', 'Dovira'))] : [],
        );
    }

    /** List-Unsubscribe + One-Click — вимога Gmail/Yahoo до масових відправників. */
    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<' . $this->unsubscribeUrl . '>, <mailto:info@mydovira.com?subject=unsubscribe>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        $rank = $this->facts['rank'] ?? null;
        $total = $this->facts['total'] ?? null;

        return new Content(
            view: 'emails.top-profile-outreach',
            with: [
                'profileName' => $this->profile->name,
                'reviews' => (int) $this->facts['reviews'],
                'rating' => number_format((float) $this->facts['rating'], 1, ',', ''),
                'category' => $this->facts['category'] ?? null,
                'city' => $this->facts['city'] ?? null,
                // Місце показуємо лише коли воно справді сильний факт.
                'rankLine' => ($rank !== null && $total !== null && $rank <= 10 && $total >= 5)
                    ? "№{$rank} за кількістю відгуків серед {$total} профілів категорії у місті"
                    : null,
                'claimUrl' => $this->claimUrl,
                'profileUrl' => $this->profileUrl,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'proPriceLine' => ProPricing::formatDisplay(ProPricing::DISPLAY_CURRENT) . ' за 6 місяців',
            ],
        );
    }
}
