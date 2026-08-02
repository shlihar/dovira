<?php

namespace App\Services;

use App\Models\Profile;
use App\Models\ProfileReview;
use Illuminate\Support\Str;

/**
 * Автомодерація гостьових відгуків: рахує ризик-бал за набором евристик
 * і повертає вердикт. Мета — не публікувати автоматично (усе одно є
 * премодерація), а відсіювати очевидний спам ще до черги модератора,
 * щоб людина не розгрібала сотні однакових повідомлень.
 */
class ReviewAutoModerationService
{
    /** Бал, від якого відгук одразу відхиляється. */
    private const REJECT_THRESHOLD = 100;

    /** Бал, від якого відгук лишається на модерації, але позначається підозрілим. */
    private const SUSPICIOUS_THRESHOLD = 40;

    /**
     * @return array{status:string,risk_score:int,is_suspicious:bool,reason:?string}
     */
    public function evaluate(Profile $profile, string $body, ?string $ip, bool $isGuest): array
    {
        $score = 0;
        $reasons = [];

        $normalized = $this->normalize($body);

        // 1. Точний дубль: той самий текст уже є на цьому профілі.
        if ($normalized !== '' && $this->isDuplicateBody($profile, $normalized)) {
            $score += 100;
            $reasons[] = 'Дубль наявного відгуку';
        }

        // 2. Флуд з однієї IP: багато відгуків за коротке вікно.
        if ($ip !== null && $ip !== '') {
            $recentFromIp = ProfileReview::query()
                ->where('author_ip', $ip)
                ->where('created_at', '>=', now()->subHours(24))
                ->count();

            if ($recentFromIp >= 10) {
                $score += 100;
                $reasons[] = 'Забагато відгуків з однієї IP за добу';
            } elseif ($recentFromIp >= 4) {
                $score += 45;
                $reasons[] = 'Підвищена активність з однієї IP';
            }
        }

        // 3. Посилання в тексті — типова ознака рекламного спаму. Кілька
        //    посилань на майданчику відгуків = майже завжди реклама → reject.
        $linkCount = preg_match_all('#(https?://|www\.|t\.me/|\b[\w-]+\.(?:com|net|org|ru|info|xyz|top|shop|site)\b)#iu', $body);
        if ($linkCount >= 3) {
            $score += 100;
            $reasons[] = 'Кілька посилань у тексті';
        } elseif ($linkCount >= 1) {
            $score += 45;
            $reasons[] = 'Посилання у тексті';
        }

        // 4. Контакти для перенаправлення (Telegram/WhatsApp/номер телефону).
        if (preg_match('#@[a-z0-9_]{4,}#i', $body) || preg_match('#(?:\+?38)?0\d{9}#', preg_replace('/[\s\-()]/', '', $body) ?? '')) {
            $score += 30;
            $reasons[] = 'Контакти у тексті відгуку';
        }

        // 5. Заспамлений формат: суцільний капс або багато повторів символів.
        $letters = preg_replace('/[^\p{L}]/u', '', $body) ?? '';
        if (mb_strlen($letters) >= 12) {
            $upper = preg_replace('/[^\p{Lu}]/u', '', $letters) ?? '';
            if (mb_strlen($upper) / mb_strlen($letters) > 0.7) {
                $score += 40;
                $reasons[] = 'Суцільний верхній регістр';
            }
        }
        if (preg_match('/(.)\1{6,}/u', $body)) {
            $score += 25;
            $reasons[] = 'Повторювані символи';
        }

        // 6. Занадто мало унікальних слів для свого обсягу (набір-заглушка).
        $words = array_filter(preg_split('/\s+/u', $normalized) ?: []);
        if (count($words) >= 6) {
            $uniqueRatio = count(array_unique($words)) / count($words);
            if ($uniqueRatio < 0.4) {
                $score += 25;
                $reasons[] = 'Низька різноманітність слів';
            }
        }

        $score = min(100, $score);

        $status = 'pending';
        if ($score >= self::REJECT_THRESHOLD) {
            $status = 'rejected';
        }

        // moderation_reason у БД — 64 символи.
        $reason = $reasons === [] ? null : Str::limit(implode('; ', $reasons), 63, '…');

        return [
            'status' => $status,
            'risk_score' => $score,
            'is_suspicious' => $score >= self::SUSPICIOUS_THRESHOLD,
            'reason' => $reason,
        ];
    }

    private function normalize(string $body): string
    {
        return trim(Str::lower(preg_replace('/\s+/u', ' ', $body) ?? ''));
    }

    private function isDuplicateBody(Profile $profile, string $normalizedBody): bool
    {
        return ProfileReview::query()
            ->where('profile_id', $profile->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->get(['body'])
            ->contains(fn (ProfileReview $review) => $this->normalize((string) $review->body) === $normalizedBody);
    }
}
