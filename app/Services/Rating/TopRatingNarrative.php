<?php

declare(strict_types=1);

namespace App\Services\Rating;

use App\Models\Profile;
use App\Models\TopRatingSnapshot;
use App\Support\Plural;
use Illuminate\Support\Collection;

/**
 * Тексти статті-рейтингу: опис під кожним профілем і абзац «що змінилось».
 *
 * Усе будується детерміновано з наших даних — без викликів AI на рендері.
 * Матеріал у порядку пріоритету: AI-розбір відгуків → досьє → структурні поля.
 *
 * ТОН: лише факти й узагальнені теми відгуків. Жодних «найкращий у місті» і
 * жодних цитат зі звинуваченнями з досьє — у списку названі живі люди.
 */
final class TopRatingNarrative
{
    /**
     * Опис профілю для картки рейтингу: 1-3 речення.
     */
    public function profileDescription(Profile $profile, int $position): ?string
    {
        $sentences = [];

        $summary = $this->aiSummarySentences($profile);
        if ($summary !== []) {
            $sentences = $summary;
        } elseif (($dossier = $this->dossierLead($profile)) !== null) {
            $sentences[] = $dossier;
        }

        if ($sentences === []) {
            $sentences[] = $this->structuralSentence($profile, $position);
        }

        $text = trim(implode(' ', array_filter($sentences)));

        return $text !== '' ? $text : null;
    }

    /**
     * Теми відгуків із AI-розбору: що хвалять і на що скаржаться.
     *
     * @return array<int, string>
     */
    public function reviewTopics(Profile $profile, int $limit = 3): array
    {
        $topics = (array) data_get($profile->ai_review_summary, 'topics', []);
        $out = [];

        foreach ($topics as $topic) {
            if (! is_array($topic) || blank($topic['topic'] ?? null)) {
                continue;
            }

            $out[] = [
                'topic' => trim((string) $topic['topic']),
                'sentiment' => in_array($topic['sentiment'] ?? '', ['positive', 'negative'], true)
                    ? (string) $topic['sentiment']
                    : 'neutral',
                'note' => trim((string) ($topic['note'] ?? '')),
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Рядок фактів під іменем: завжди унікальний для профілю.
     */
    public function factsLine(Profile $profile, array $components): string
    {
        $facts = [];
        $rating = $profile->rating;
        $total = max(1, (int) ($rating->reviews_total ?? $profile->reviews_count));
        $fiveShare = (int) round(((int) ($rating->dist_5 ?? 0)) / $total * 100);

        $facts[] = number_format((float) $profile->rating_avg, 2, ',', ' ') . ' з 5 за '
            . $profile->reviews_count . ' ' . Plural::uk((int) $profile->reviews_count, 'відгуком', 'відгуками', 'відгуками');

        if ($fiveShare > 0) {
            $facts[] = $fiveShare . '% пʼятірок';
        }

        return implode(' · ', $facts);
    }

    /**
     * Абзац «що змінилось» — різниця між поточним і попереднім зрізом.
     *
     * @param  Collection<int, TopRatingSnapshot>  $current
     * @param  Collection<int, TopRatingSnapshot>  $previous
     */
    public function changesParagraph(Collection $current, Collection $previous, string $periodLabel): ?string
    {
        if ($previous->isEmpty()) {
            return null;
        }

        $prevByProfile = $previous->keyBy('profile_id');
        $currByProfile = $current->keyBy('profile_id');
        $parts = [];

        $newcomers = $current->filter(fn ($row) => ! $prevByProfile->has($row->profile_id));
        if ($newcomers->isNotEmpty()) {
            $names = $newcomers->take(2)->map(fn ($row) => $this->shortName($row->profile?->name))->filter()->all();
            if ($names !== []) {
                $parts[] = count($names) === 1
                    ? 'У десятку вперше увійшов ' . $names[0] . '.'
                    : 'У десятку вперше увійшли ' . implode(' і ', $names) . '.';
            }
        }

        $risen = $current
            ->map(function ($row) use ($prevByProfile) {
                $prev = $prevByProfile->get($row->profile_id);

                return $prev ? ['row' => $row, 'delta' => (int) $prev->position - (int) $row->position] : null;
            })
            ->filter(fn ($x) => $x !== null && $x['delta'] > 0)
            ->sortByDesc('delta')
            ->first();

        if ($risen !== null) {
            $name = $this->shortName($risen['row']->profile?->name);
            $prev = $prevByProfile->get($risen['row']->profile_id);
            if ($name !== null) {
                $parts[] = $name . ' піднявся з ' . $prev->position . '-го місця на ' . $risen['row']->position . '-те.';
            }
        }

        $left = $previous->filter(fn ($row) => ! $currByProfile->has($row->profile_id));
        if ($left->isNotEmpty()) {
            $name = $this->shortName($left->first()->profile?->name);
            if ($name !== null) {
                $parts[] = 'Із топу вибув ' . $name . '.';
            }
        }

        $freshTotal = (int) $current->sum('reviews_last_90d');
        if ($freshTotal > 0) {
            $parts[] = 'Разом учасники рейтингу отримали ' . $freshTotal . ' '
                . Plural::uk($freshTotal, 'новий відгук', 'нові відгуки', 'нових відгуків') . ' за останні 90 днів.';
        }

        if ($parts === []) {
            return 'За ' . $periodLabel . ' склад десятки не змінився.';
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<int, string>
     */
    private function aiSummarySentences(Profile $profile): array
    {
        $summary = trim((string) data_get($profile->ai_review_summary, 'summary', ''));

        if ($summary === '') {
            return [];
        }

        // Беремо перші два речення: решта повторює те, що і так видно в темах.
        $sentences = preg_split('/(?<=[.!?])\s+/u', $summary) ?: [];

        return array_slice(array_filter(array_map('trim', $sentences)), 0, 2);
    }

    private function dossierLead(Profile $profile): ?string
    {
        $dossier = trim((string) $profile->dossier);

        if ($dossier === '') {
            return null;
        }

        // Перший абзац досьє — фактологічний лід (хто це, з якого року).
        // Далі в тексті йдуть оцінки й цитати, які в рейтинг не виносимо.
        $lead = preg_split('/\n\s*\n/u', $dossier)[0] ?? '';
        $lead = trim(preg_replace('/[*_>#]+/u', '', $lead) ?? '');

        if ($lead === '' || mb_strlen($lead) < 40) {
            return null;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $lead) ?: [];
        $first = trim((string) ($sentences[0] ?? ''));

        // Буває, що перше речення досьє — сама лише назва («Адвокат Іваненко
        // Іван Іванович.»). Такий «опис» нічого не додає, тож беремо ще одне
        // речення, а якщо й разом коротко — віддаємо роботу структурному тексту.
        if (mb_strlen($first) < 60 && isset($sentences[1])) {
            $first = trim($first . ' ' . trim((string) $sentences[1]));
        }

        return mb_strlen($first) >= 60 ? $first : null;
    }

    /**
     * Опис зі структурних полів, коли немає ні AI-розбору, ні досьє.
     * Пишемо про сам профіль (місто, спеціалізація, що кажуть оцінки), а не
     * про його місце в рейтингу: «посідає 4-ту позицію» — це текст про
     * таблицю, а не про виконавця, і читачеві він нічого не дає.
     */
    private function structuralSentence(Profile $profile, int $position): string
    {
        $name = trim((string) $profile->name);
        $city = trim((string) $profile->city);
        $reviews = (int) $profile->reviews_count;
        $rating = number_format((float) $profile->rating_avg, 2, ',', ' ');

        $ratingRow = $profile->rating;
        $total = max(1, (int) ($ratingRow->reviews_total ?? $reviews));
        $fiveShare = (int) round(((int) ($ratingRow->dist_5 ?? 0)) / $total * 100);

        $specialization = $profile->relationLoaded('categories')
            ? $profile->categories
                ->pluck('name')
                ->map(fn ($n) => mb_strtolower(trim((string) $n)))
                ->filter()
                ->first()
            : null;

        $where = $city !== '' ? ' з міста ' . $city : '';
        $reviewsWord = Plural::uk($reviews, 'відгук', 'відгуки', 'відгуків');

        // Шаблон обираємо за id профілю, щоб десять описів на одній сторінці
        // не читались однаково.
        $templates = [
            fn (): string => $name . ($specialization ? ' — ' . $specialization : '') . $where
                . '. Клієнти залишили ' . $reviews . ' ' . $reviewsWord
                . ($fiveShare > 0 ? ', ' . $fiveShare . '% із них — найвищі оцінки.' : '.'),
            fn (): string => 'За ' . $reviews . ' ' . Plural::uk($reviews, 'відгуком', 'відгуками', 'відгуками')
                . ' середня оцінка — ' . $rating . ' з 5'
                . ($city !== '' ? '. Працює в місті ' . $city : '') . '.',
            fn (): string => ($city !== '' ? 'Місто: ' . $city . '. ' : '')
                . 'У профілі ' . $reviews . ' ' . $reviewsWord . ' із середньою оцінкою ' . $rating
                . ($fiveShare >= 70 ? ', переважна більшість — позитивні.' : '.'),
        ];

        return $templates[$profile->id % count($templates)]();
    }

    private function shortName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name !== '' ? mb_substr($name, 0, 60) : null;
    }
}
