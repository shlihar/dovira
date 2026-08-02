<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Resolves an owner-provided CTA link (messenger, social network, booking
 * form or plain website) into a ready-to-render action button: brand icon,
 * human label and the matching analytics event type.
 */
final class ContactCta
{
    /**
     * @return array{href:string,label:string,detail:string,icon:string,track:string}|null
     */
    public static function resolve(?string $value): ?array
    {
        $href = WebsiteUrl::href($value);
        if ($href === null) {
            return null;
        }

        $host = Str::lower((string) (parse_url($href, PHP_URL_HOST) ?? ''));
        $host = Str::startsWith($host, 'www.') ? (string) Str::after($host, 'www.') : $host;

        [$label, $icon, $track] = match (true) {
            in_array($host, ['t.me', 'telegram.me', 'telegram.org'], true) => ['Написати в Telegram', 'fa-brands fa-telegram', 'telegram_click'],
            in_array($host, ['wa.me', 'api.whatsapp.com', 'chat.whatsapp.com', 'whatsapp.com'], true) => ['Написати у WhatsApp', 'fa-brands fa-whatsapp', 'whatsapp_click'],
            in_array($host, ['invite.viber.com', 'viber.com', 'viber.click', 'vb.me'], true) => ['Написати у Viber', 'fa-brands fa-viber', 'viber_click'],
            $host === 'instagram.com' => ['Написати в Instagram', 'fa-brands fa-instagram', 'instagram_click'],
            in_array($host, ['facebook.com', 'fb.com', 'fb.me', 'm.me', 'messenger.com'], true) => ['Написати у Facebook', 'fa-brands fa-facebook-f', 'facebook_click'],
            default => ['Звʼязатися онлайн', 'fa-solid fa-paper-plane', 'website_click'],
        };

        return [
            'href' => $href,
            'label' => $label,
            'detail' => (string) (WebsiteUrl::display($value) ?? $host),
            'icon' => $icon,
            'track' => $track,
        ];
    }
}
