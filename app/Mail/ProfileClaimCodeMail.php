<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProfileClaimCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public string $profileName,
        public int $ttlMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Код підтвердження профілю на Dovira: ' . $this->code,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.claim-code',
            with: [
                'code' => $this->code,
                'profileName' => $this->profileName,
                'ttlMinutes' => $this->ttlMinutes,
            ],
        );
    }
}
