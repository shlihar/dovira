<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProfileClaimApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $profileName,
        public string $actionUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Профіль підтверджено на DOVIRA: ' . $this->profileName,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.profile-claim-approved',
            with: [
                'profileName' => $this->profileName,
                'actionUrl' => $this->actionUrl,
            ],
        );
    }
}
