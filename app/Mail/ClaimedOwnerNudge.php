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
 * Лист власникам, які підтвердили профіль, але не оформили PRO.
 * Кут: сторінку вже показують клієнтам, а відгуки стоять без відповіді
 * компанії (dovira:outreach:claimed-nudge).
 *
 * Усі цифри — РЕАЛЬНІ з нашої бази. «Без відповіді» завжди з уточненням
 * «на DOVIRA»: імпорт не переносить відповіді з інших майданчиків, і саме
 * це формулювання коштувало нам клієнта 14.09.2026 («Мережа права»).
 *
 * Дві фіксовані теми (за ними дедуп у команді):
 *   є відгуки  → «Відгуки про «X» чекають на вашу відповідь»
 *   нема        → «Сторінка «X» на DOVIRA: що зробити далі»
 *
 * @param  array{reviews:int, negative_unanswered:int, unanswered:int, impressions90:int}  $facts
 */
class ClaimedOwnerNudge extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Profile $profile,
        public array $facts,
        public string $reviewsUrl,
        public string $billingUrl,
        public string $profileUrl,
    ) {
    }

    public static function subjectFor(Profile $profile, int $reviews): string
    {
        return $reviews > 0
            ? "Відгуки про «{$profile->name}» чекають на вашу відповідь"
            : "Сторінка «{$profile->name}» на DOVIRA: що зробити далі";
    }

    /** Патерни тем для постійного дедупу — один такий лист на адресу назавжди. */
    public static function subjectPatterns(): array
    {
        return ['Відгуки про%чекають на вашу відповідь', 'Сторінка «%» на DOVIRA: що зробити далі'];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::subjectFor($this->profile, (int) $this->facts['reviews']));
    }

    public function content(): Content
    {
        $impressions = (int) $this->facts['impressions90'];

        return new Content(
            view: 'emails.claimed-owner-nudge',
            with: [
                'profileName' => $this->profile->name,
                'reviews' => (int) $this->facts['reviews'],
                'unanswered' => (int) $this->facts['unanswered'],
                'negativeUnanswered' => (int) $this->facts['negative_unanswered'],
                // Показуємо лише коли цифра сама по собі аргумент.
                'impressions90' => $impressions >= 10 ? $impressions : null,
                'reviewsUrl' => $this->reviewsUrl,
                'billingUrl' => $this->billingUrl,
                'profileUrl' => $this->profileUrl,
                'proPriceLine' => ProPricing::formatDisplay(ProPricing::DISPLAY_CURRENT) . ' за '
                    . ProPricing::PERIOD_MONTHS . ' місяців ('
                    . ProPricing::monthlyEquivalentDisplay(ProPricing::DISPLAY_CURRENT) . ')',
            ],
        );
    }
}
