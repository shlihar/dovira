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
 * Лист власнику про успішну оплату PRO: підтвердження платежу, дата
 * завершення періоду й що робити далі. $isRenewal=true — продовження
 * наявної підписки, інакше перша активація.
 *
 * Раніше після оплати клієнт отримував лише in-app сповіщення в кабінеті —
 * жодного email (кейс 14.09.2026: «оплатили, а сповіщення не прийшло»).
 */
class ProSubscriptionActivated extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Profile $profile,
        public ProSubscription $subscription,
        public int $amount,
        public bool $isRenewal = false,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->isRenewal
            ? 'PRO-підписку продовжено — ' . $this->profile->name
            : 'PRO активовано — ' . $this->profile->name);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pro-subscription-activated',
            with: [
                'profileName' => $this->profile->name,
                'isRenewal' => $this->isRenewal,
                'amountDisplay' => ProPricing::formatAmount($this->amount),
                'endsAtDisplay' => $this->subscription->ends_at?->format('d.m.Y') ?? '',
                'autoRenew' => (bool) $this->subscription->auto_renew,
                'periodMonths' => ProPricing::PERIOD_MONTHS,
                'accountUrl' => route('pro.account', ['profile' => $this->profile->id]),
                'reviewsUrl' => route('pro.account', ['tab' => 'reviews', 'profile' => $this->profile->id]),
                'profileUrl' => route('profile.show', ['slug' => $this->profile->slug]),
            ],
        );
    }
}
