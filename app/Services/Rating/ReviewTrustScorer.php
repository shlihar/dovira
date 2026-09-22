<?php

declare(strict_types=1);

namespace App\Services\Rating;

/**
 * Легкий антифрод: рахує trust_score відгуку (0..1) за дешевими сигналами,
 * які видно з самого запису. Важчі сигнали (сплески з одного IP, дублікати
 * тексту, буст-акаунти) — окремим кроком пізніше; тут не робимо N+1.
 *
 * Важливо: до google_import штрафи НЕ застосовуємо — вони вже понижені через
 * вагу джерела (×0.2), подвійне пониження було б несправедливим.
 * Виключений відгук лишається ВИДИМИМ, лише не впливає на число.
 */
class ReviewTrustScorer
{
    /**
     * @param object $review запис із полями source, body, author_phone_verified, author_email_verified, manually_reviewed, trust_score
     * @return array{trust: float, excluded: bool, reason: string}
     */
    public function score(object $review, array $cfg): array
    {
        // Ручний перегляд «заморожує» рішення — антифрод його не чіпає.
        if (! empty($review->manually_reviewed)) {
            $trust = (float) ($review->trust_score ?? 1.0);

            return ['trust' => $trust, 'excluded' => $trust < $cfg['exclude_below'], 'reason' => (string) ($review->exclusion_reason ?? '')];
        }

        $trust = 1.0;
        $flags = [];

        if ((string) $review->source !== 'google_import') {
            $penalties = $cfg['penalties'];

            if (empty($review->author_phone_verified) && empty($review->author_email_verified)) {
                $trust -= (float) $penalties['no_contact_verified'];
                $flags[] = 'no_contact_verified';
            }

            if (mb_strlen(trim((string) ($review->body ?? ''))) < (int) $cfg['min_text_length']) {
                $trust -= (float) $penalties['short_text'];
                $flags[] = 'short_text';
            }
        }

        $trust = max(0.0, min(1.0, $trust));
        $excluded = $trust < (float) $cfg['exclude_below'];

        return [
            'trust' => $trust,
            'excluded' => $excluded,
            'reason' => $excluded ? implode(',', $flags) : '',
        ];
    }
}
