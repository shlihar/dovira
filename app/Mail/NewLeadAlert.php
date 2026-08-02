<?php

namespace App\Mail;

use App\Models\Profile;
use App\Models\ProfileLead;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Тригер-лист власнику профілю про нову заявку клієнта.
 * Контакти клієнта показуємо лише PRO-власникам — для решти телефон
 * маскується так само, як у кабінеті (розблокування — аргумент PRO).
 */
class NewLeadAlert extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Profile $profile,
        public ProfileLead $lead,
        public bool $contactsUnlocked,
        public string $ctaUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Нова заявка від клієнта — ' . $this->profile->name,
        );
    }

    public function content(): Content
    {
        $phone = trim((string) $this->lead->phone);
        $maskedPhone = '••• ••• •• ' . mb_substr(preg_replace('/\D+/', '', $phone) ?: '••', -2);

        return new Content(
            view: 'emails.new-lead-alert',
            with: [
                'profileName' => $this->profile->name,
                'leadName' => (string) $this->lead->name,
                'leadPhone' => $this->contactsUnlocked ? $phone : $maskedPhone,
                'leadMessage' => \Illuminate\Support\Str::limit(trim((string) $this->lead->message), 400),
                'contactsUnlocked' => $this->contactsUnlocked,
                'ctaUrl' => $this->ctaUrl,
            ],
        );
    }
}
