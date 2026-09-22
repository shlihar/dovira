<?php

namespace App\Mail;

use App\Models\Profile;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Один лист post-claim nurture-послідовності. Крок приходить уже «зібраним»
 * (плейсхолдери підставлені) з ProNurtureMailer — mailable лише рендерить.
 *
 * @param  array{subject:string, eyebrow:string, heading:string, body:array<int,string>, cta_label:string, cta_url:string}  $step
 */
class ProNurtureEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Profile $profile,
        public array $step,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) $this->step['subject']);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pro-nurture',
            with: [
                'eyebrow' => (string) ($this->step['eyebrow'] ?? ''),
                'heading' => (string) ($this->step['heading'] ?? ''),
                'paragraphs' => (array) ($this->step['body'] ?? []),
                'ctaLabel' => (string) ($this->step['cta_label'] ?? 'Відкрити кабінет'),
                'ctaUrl' => (string) ($this->step['cta_url'] ?? ''),
            ],
        );
    }
}
