<?php

namespace App\Mail;

use App\Models\Profile;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Холодний аутріч-лист кампанії /rozsylka поштою. Тіло вже зібране як БЕЗПЕЧНИЙ
 * HTML (текст екранований, у місце плейсхолдера вставлено інлайн-посилання)
 * у SendOutreachEmailCampaign::buildLetter. Тут лише рендер.
 *
 * @param  array{subject:string, body_html:string, body_text:string}  $letter
 */
class OutreachCampaignEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Profile $profile,
        public array $letter,
        public string $unsubscribeUrl,
        public string $claimUrl = '',
    ) {
    }

    public function envelope(): Envelope
    {
        // From задаємо явно (піддомен розсилки), якщо налаштовано в config/outreach.
        $fromAddress = trim((string) config('outreach.from.address'));
        $replyToAddress = trim((string) config('outreach.reply_to.address'));

        return new Envelope(
            subject: (string) $this->letter['subject'],
            from: $fromAddress !== ''
                ? new \Illuminate\Mail\Mailables\Address($fromAddress, (string) config('outreach.from.name', 'Dovira'))
                : null,
            replyTo: $replyToAddress !== ''
                ? [new \Illuminate\Mail\Mailables\Address($replyToAddress, (string) config('outreach.reply_to.name', 'Dovira'))]
                : [],
        );
    }

    /**
     * List-Unsubscribe + One-Click — вимога Gmail/Yahoo до масових відправників
     * (2024). Без цих заголовків лист штрафується навіть за валідних SPF/DKIM/DMARC.
     */
    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<' . $this->unsubscribeUrl . '>, <mailto:info@mydovira.com?subject=unsubscribe>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        // HTML + текстова версія: multipart-лист краще проходить спам-фільтри,
        // ніж HTML-only.
        return new Content(
            view: 'emails.outreach-campaign',
            text: 'emails.outreach-campaign-text',
            with: [
                'profileName' => $this->profile->name,
                'bodyHtml' => (string) $this->letter['body_html'],
                'bodyText' => (string) ($this->letter['body_text'] ?? ''),
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'claimUrl' => $this->claimUrl,
            ],
        );
    }
}
