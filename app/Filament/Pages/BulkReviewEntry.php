<?php

namespace App\Filament\Pages;

use App\Models\Profile;
use App\Models\ProfileReview;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Швидкий ручний ввід відгуків (наприклад, скопійованих з Google): обираєш
 * профіль, вписуєш імʼя/текст/рейтинг/дату → Enter → відгук опублікований,
 * форма очищається й фокус одразу на наступний. Позначка «з Google» вмикається
 * тумблером і зберігається між записами.
 */
class BulkReviewEntry extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-pencil-square';

    protected string $view = 'filament.pages.bulk-review-entry';

    public ?int $profileId = null;

    public string $profileName = '';

    public string $search = '';

    public bool $googleBadge = true;

    public string $authorName = '';

    public int $rating = 5;

    public string $reviewDate = '';

    public string $body = '';

    public int $addedCount = 0;

    public ?int $lastReviewId = null;

    /** @var array<int, array{author: string, rating: int}> */
    public array $recent = [];

    public function mount(): void
    {
        $this->reviewDate = now()->format('Y-m-d');
    }

    public static function getNavigationLabel(): string
    {
        return 'Швидкий ввід відгуків';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Відгуки';
    }

    public function getTitle(): string
    {
        return 'Швидкий ввід відгуків';
    }

    /**
     * @return array<int, array{id: int, name: string, city: ?string, reviews: int}>
     */
    public function getMatchesProperty(): array
    {
        $term = trim($this->search);
        if (mb_strlen($term) < 2) {
            return [];
        }

        return Profile::query()
            ->where('name', 'like', "%{$term}%")
            ->orderByDesc('reviews_count')
            ->limit(8)
            ->get(['id', 'name', 'city', 'reviews_count'])
            ->map(fn (Profile $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'city' => $p->city,
                'reviews' => (int) $p->reviews_count,
            ])
            ->all();
    }

    public function selectProfile(int $id, string $name): void
    {
        $this->profileId = $id;
        $this->profileName = $name;
        $this->search = '';
        $this->addedCount = 0;
        $this->recent = [];
        $this->dispatch('focus-author');
    }

    public function clearProfile(): void
    {
        $this->profileId = null;
        $this->profileName = '';
    }

    public function save(): void
    {
        if (! $this->profileId) {
            return;
        }

        $data = Validator::make([
            'authorName' => trim($this->authorName),
            'rating' => $this->rating,
            'body' => trim($this->body),
            'reviewDate' => $this->reviewDate ?: now()->format('Y-m-d'),
        ], [
            'authorName' => ['required', 'string', 'max:255'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['required', 'string', 'max:5000'],
            'reviewDate' => ['required', 'date'],
        ])->validate();

        $review = $this->createReview(
            $data['authorName'],
            (int) $data['rating'],
            $data['body'],
            Carbon::parse($data['reviewDate']),
        );

        // Дубль — очищаємо форму без додавання й попереджаємо.
        if (! $review) {
            $this->authorName = '';
            $this->body = '';
            $this->rating = 5;
            $this->dispatch('focus-author');

            \Filament\Notifications\Notification::make()
                ->title('Такий відгук вже існує — пропущено')
                ->warning()
                ->send();

            return;
        }

        app(\App\Services\ProfileReviewStatsService::class)->recalculateForProfileId((int) $this->profileId);

        $this->lastReviewId = $review->id;
        $this->addedCount++;
        array_unshift($this->recent, ['author' => $data['authorName'], 'rating' => (int) $data['rating']]);
        $this->recent = array_slice($this->recent, 0, 8);

        // Очищаємо поля відгуку, лишаємо профіль, дату і тумблер.
        $this->authorName = '';
        $this->body = '';
        $this->rating = 5;

        \Filament\Notifications\Notification::make()
            ->title('Відгук опубліковано')
            ->success()
            ->send();

        $this->dispatch('focus-author');
    }

    /** Скасувати щойно доданий відгук (видалити). */
    public function deleteLast(): void
    {
        if (! $this->lastReviewId) {
            return;
        }

        // Через модель, щоб обсервер перерахував рейтинг профілю.
        ProfileReview::find($this->lastReviewId)?->delete();
        $this->lastReviewId = null;
        $this->addedCount = max(0, $this->addedCount - 1);
        array_shift($this->recent);
        $this->dispatch('focus-author');
    }

    private function createReview(string $author, int $rating, string $body, Carbon $date): ?ProfileReview
    {
        // Захист від дублів: той самий профіль/автор/текст/оцінка вже є —
        // не створюємо повторно (повторна вставка того самого блоку).
        $exists = ProfileReview::query()
            ->where('profile_id', $this->profileId)
            ->where('author_name', $author)
            ->where('body', $body)
            ->where('rating', max(1, min(5, $rating)))
            ->exists();

        if ($exists) {
            return null;
        }

        // «Перекладний» дубль: Google авто-перекладає відгуки, тож раніше
        // імпортована українська версія і вставлений оригінал мають різний
        // текст. Той самий автор + оцінка + дата ±90 днів + схожість тексту
        // ≥55% (переклади RU↔UA посимвольно схожі, різні історії — ні).
        $candidates = ProfileReview::query()
            ->where('profile_id', $this->profileId)
            ->whereRaw('LOWER(author_name) = ?', [mb_strtolower($author)])
            ->where('rating', max(1, min(5, $rating)))
            ->whereNotNull('external_source_type')
            ->whereRaw(
                'ABS(DATEDIFF(COALESCE(external_review_date, published_at, created_at), ?)) <= 90',
                [$date->format('Y-m-d')]
            )
            ->pluck('body');

        foreach ($candidates as $candidateBody) {
            $a = mb_strtolower(mb_substr(trim($body), 0, 600));
            $b = mb_strtolower(mb_substr(trim((string) $candidateBody), 0, 600));
            if ($a === '' && $b === '') {
                return null; // обидва без тексту — той самий відгук-оцінка
            }
            similar_text($a, $b, $percent);
            if ($percent >= 55.0) {
                return null;
            }
        }

        // createQuietly: без обсервера — інакше на КОЖЕН відгук ідуть перерахунок
        // статистики по віддаленій БД, AI-job у чергу, сповіщення власнику та
        // email про негатив (для імпортованих це і повільно, і неправильно).
        // Статистика перераховується один раз після збереження/публікації.
        return ProfileReview::createQuietly([
            'profile_id' => $this->profileId,
            'user_id' => null,
            'author_name' => $author,
            'rating' => max(1, min(5, $rating)),
            'body' => $body,
            'status' => 'published',
            'published_at' => $date,
            'is_anonymous' => false,
            'verification_type' => $this->googleBadge ? 'external_google_import' : null,
            'external_source_type' => $this->googleBadge ? 'google' : null,
            'external_review_author' => $author,
            'external_review_date' => $date,
            // sha1 (40 симв.) — вміщується в колонку string(40); sha256 у
            // strict-режимі MySQL падав з «Data too long» (так було на хостингу).
            'external_review_hash' => sha1($this->profileId.'|manual|'.$author.'|'.$body),
        ]);
    }

    // ─── Масова вставка з Google ───

    public bool $pasteMode = false;

    public string $pasteRaw = '';

    /** @var array<int, array{author: string, date: string, text: string, rating: int}> */
    public array $parsed = [];

    public function togglePaste(): void
    {
        $this->pasteMode = ! $this->pasteMode;
        $this->parsed = [];
        $this->pasteRaw = '';
    }

    /**
     * Розбирає скопійований з Google блок відгуків на ім'я/дату/текст.
     * Відповіді власника й службові рядки («Нравится», «Поделиться») відкидає.
     * Рейтинг у копії відсутній (зірки — картинки), тож ставиться 5 за замовч.
     */
    public function parsePaste(): void
    {
        // Прибираємо все службове: керуючі/форматні символи (\p{C}), символи-
        // заглушки й емодзі (\p{So}, \p{Sk}) і різні пробіли — Google лишає їх
        // від фото-прев'ю та іконок, і вони протікають у текст/імена.
        $clean = fn (string $s): string => trim(preg_replace(
            '/[\p{C}\p{So}\p{Sk}\x{00A0}\x{202F}\x{2028}\x{2029}]+/u',
            ' ',
            $s
        ));

        $lines = array_values(array_filter(
            array_map($clean, preg_split('/\r\n|\r|\n/', $this->pasteRaw) ?: []),
            fn ($l) => $l !== ''
        ));

        $ownerRe = '/^(Ответ владельца|Відповідь власника)/iu';
        // Дата відгуку — короткий рядок, що закінчується на «назад/тому», або «вчора/сьогодні».
        $dateRe = '/^(.{1,22}\s(назад|тому)|вчера|сегодня|вчора|сьогодні)$/iu';
        // Службові рядки, що завершують/не є текстом. Переклад-лінки Google
        // («Переглянути переклад», «Перекладено Google…») завершують текст.
        $stopRe = '/^(Нравится|Подобається|Поделиться|Поділитися|Ответить|Відповісти|Like|Share|Reply|Отрицательные|Положительные|Профессионализм|Качество|Цена|\d+)$'
            .'|^(Переглянути переклад|Посмотреть перевод|See translation|Перекладено Google|Переведено Google)/iu';
        // Шумові рядки-бейджі, які просто пропускаємо (стоять перед текстом).
        $skipRe = '/^(НОВИЙ|НОВОЕ|NEW)$/u';
        // Мета: лічильники відгуків/фото, «Местный эксперт».
        $metaRe = '/(\d+\s*(отзыв|відгук|фото|review|photo))|^(Местный эксперт|Local Guide|Місцевий експерт)/iu';

        // Якорі — рядки з датою відгуку (не відповідь власника).
        $anchors = [];
        foreach ($lines as $i => $l) {
            if (preg_match($dateRe, $l) && ! preg_match($ownerRe, $l)) {
                $anchors[] = $i;
            }
        }

        // Автор кожного відгуку — найближчий зверху рядок з іменем (пропускаємо
        // мета-рядки й будь-що без літер: заглушки фото, цифри, символи).
        $recs = [];
        foreach ($anchors as $d) {
            $a = $d - 1;
            while ($a >= 0 && (preg_match($metaRe, $lines[$a]) || ! preg_match('/\p{L}{2,}/u', $lines[$a]))) {
                $a--;
            }
            if ($a < 0) {
                continue;
            }
            $author = $lines[$a];
            if (preg_match($ownerRe, $author) || preg_match($dateRe, $author) || preg_match($stopRe, $author)) {
                continue;
            }
            $recs[] = ['d' => $d, 'authorIdx' => $a, 'author' => $author];
        }

        // Текст — до автора наступного відгуку, з обрізанням на службових рядках.
        $parsed = [];
        foreach ($recs as $k => $r) {
            $end = $recs[$k + 1]['authorIdx'] ?? count($lines);
            $t = [];
            for ($j = $r['d'] + 1; $j < $end; $j++) {
                if (preg_match($skipRe, $lines[$j])) {
                    continue; // бейдж «НОВИЙ» тощо — пропускаємо, текст далі
                }
                if (preg_match($stopRe, $lines[$j]) || preg_match($ownerRe, $lines[$j]) || preg_match($metaRe, $lines[$j])) {
                    break;
                }
                $t[] = $lines[$j];
            }
            $text = trim(preg_replace('/\s+/u', ' ', $clean(implode(' ', $t))));
            // Залишки UI Google усередині рядка: бейдж «НОВИЙ», хвіст перекладу,
            // маркер обрізання «…Більше».
            $text = trim((string) preg_replace([
                '/(?<=^|\s)(НОВИЙ|НОВОЕ)(?=\s|$)/u',
                '/(Перекладено Google|Переведено Google).{0,60}$/u',
                '/(Переглянути переклад|Посмотреть перевод|See translation)\s*(\([^)]*\))?/iu',
                '/\s*…\s*(Більше|Ещё|Еще|More)\b/u',
            ], ' ', $text));
            $text = trim(preg_replace('/\s+/u', ' ', $text));
            $parsed[] = [
                'author' => $clean($r['author']),
                'date' => $this->relativeToDate($lines[$r['d']])->format('Y-m-d'),
                'text' => $text,
                'rating' => 5,
            ];
        }

        $this->parsed = $parsed;
    }

    public function setAllParsedRating(int $rating): void
    {
        $rating = max(1, min(5, $rating));
        // Переприсвоюємо масив цілком, щоб Livewire точно перемалював зірки.
        $this->parsed = array_map(fn ($p) => [...$p, 'rating' => $rating], $this->parsed);
    }

    public function setParsedRating(int $index, int $rating): void
    {
        if (isset($this->parsed[$index])) {
            $parsed = $this->parsed;
            $parsed[$index]['rating'] = max(1, min(5, $rating));
            $this->parsed = $parsed;
        }
    }

    public function removeParsed(int $index): void
    {
        unset($this->parsed[$index]);
        $this->parsed = array_values($this->parsed);
    }

    public function publishParsed(): void
    {
        if (! $this->profileId) {
            return;
        }

        $created = 0;
        $skipped = 0;
        foreach ($this->parsed as $r) {
            $author = trim((string) $r['author']);
            if ($author === '') {
                $skipped++;

                continue;
            }
            // Текст може бути порожнім (відгук лише із зіркою) — це нормально.
            $review = $this->createReview($author, (int) $r['rating'], trim((string) $r['text']), Carbon::parse($r['date']));
            if ($review) {
                $this->addedCount++;
                $created++;
            } else {
                $skipped++;
            }
        }

        if ($created > 0) {
            // Один раз на весь пакет: перерахунок рейтингу й оновлення AI-аналізу.
            app(\App\Services\ProfileReviewStatsService::class)->recalculateForProfileId((int) $this->profileId);
            \App\Jobs\RefreshProfileReviewAiAnalysis::dispatch((int) $this->profileId);
        }

        \Filament\Notifications\Notification::make()
            ->title($created > 0 ? "Опубліковано {$created} відгуків" : 'Нічого не опубліковано')
            ->body($skipped > 0 ? "Пропущено {$skipped} (дублі або без імені)." : null)
            ->status($created > 0 ? 'success' : 'warning')
            ->send();

        $this->parsed = [];
        $this->pasteRaw = '';
        $this->lastReviewId = null;
    }

    private function relativeToDate(string $s): Carbon
    {
        $s = mb_strtolower($s);
        if (str_contains($s, 'вчора') || str_contains($s, 'вчера')) {
            return now()->subDay();
        }
        if (str_contains($s, 'сьогодні') || str_contains($s, 'сегодня')) {
            return now();
        }
        preg_match('/(\d+)/', $s, $m);
        $n = isset($m[1]) ? max(1, (int) $m[1]) : 1;

        return match (true) {
            (bool) preg_match('/(год|года|лет|рік|рок)/u', $s) => now()->subYears($n),
            (bool) preg_match('/(месяц|місяц)/u', $s) => now()->subMonths($n),
            (bool) preg_match('/(недел|тиж)/u', $s) => now()->subWeeks($n),
            (bool) preg_match('/(дн|день|дня|дней|дні)/u', $s) => now()->subDays($n),
            (bool) preg_match('/(час|годин)/u', $s) => now()->subHours($n),
            default => now(),
        };
    }

    public function setDate(string $preset): void
    {
        $this->reviewDate = match ($preset) {
            'today' => now()->format('Y-m-d'),
            'week' => now()->subWeek()->format('Y-m-d'),
            'month' => now()->subMonth()->format('Y-m-d'),
            'halfyear' => now()->subMonths(6)->format('Y-m-d'),
            'year' => now()->subYear()->format('Y-m-d'),
            default => $this->reviewDate,
        };
        $this->dispatch('focus-author');
    }
}
