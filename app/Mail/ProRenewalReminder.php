<?php

namespace App\Mail;

use App\Models\Profile;
use App\Models\ProSubscription;
use App\Support\ProPricing;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Нагадування власнику про завершення PRO-підписки. Три хвилі за стадією
 * ($stage): 'week' (≈7 днів до кінця), 'days3', 'day1'. Тема містить дату
 * завершення — вона ж працює дедупом у email_logs, тож наступний період
 * (інша дата) отримає нагадування знову.
 *
 * $manualMode=true — міграція на новий мерчант monobank: пояснюємо, що
 * цього разу продовження треба оплатити вручну (стара картка не спишеться).
 */
class ProRenewalReminder extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Profile $profile,
        public ProSubscription $subscription,
        public string $stage,
        public bool $manualMode,
    ) {
    }

    public static function subjectFor(Profile $profile, ProSubscription $subscription, string $stage): string
    {
        $date = $subscription->ends_at?->format('d.m.Y') ?? '';

        return match ($stage) {
            'week' => "PRO для «{$profile->name}» діє до {$date} — продовжіть заздалегідь",
            'days3' => "Залишилося 3 дні: PRO для «{$profile->name}» закінчується {$date}",
            default => "Завтра ({$date}) «{$profile->name}» повернеться на безкоштовний тариф",
        };
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::subjectFor($this->profile, $this->subscription, $this->stage));
    }

    public function content(): Content
    {
        $amount = (int) ($this->subscription->price_monthly ?: ProPricing::CURRENT_AMOUNT);

        return new Content(
            view: 'emails.pro-renewal-reminder',
            with: [
                'profileName' => $this->profile->name,
                'endsAtDisplay' => $this->subscription->ends_at?->format('d.m.Y') ?? '',
                'stage' => $this->stage,
                'manualMode' => $this->manualMode,
                'amountDisplay' => ProPricing::formatAmount($amount),
                'periodMonths' => ProPricing::PERIOD_MONTHS,
                'payUrl' => route('pro.account.billing.pay', $this->profile),
            ],
        );
    }
}
