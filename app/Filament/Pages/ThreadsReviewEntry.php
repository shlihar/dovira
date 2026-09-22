<?php

namespace App\Filament\Pages;

use App\Models\Profile;
use App\Models\ProfileReview;
use App\Models\ReviewReply;
use App\Services\ProfileReviewStatsService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Validator;
use Livewire\WithFileUploads;

/**
 * Швидке створення відгуку з Threads: обрав профіль → автор/оцінка/текст →
 * лінк на пост → коментарі (по рядку «Ім'я: текст») → Опублікувати. Відгук
 * одразу публікується з бейджем «Відгук з Threads», коментарі — під ним.
 */
class ThreadsReviewEntry extends Page
{
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected string $view = 'filament.pages.threads-review-entry';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $photos = [];

    public ?int $profileId = null;

    public string $profileName = '';

    public string $search = '';

    public string $authorName = '';

    public int $rating = 5;

    public string $body = '';

    public string $threadsUrl = '';

    public string $comments = '';

    public int $addedCount = 0;

    public ?int $lastReviewId = null;

    /** @var array<int, array{author: string, rating: int, comments: int}> */
    public array $recent = [];

    public static function getNavigationLabel(): string
    {
        return 'Відгук з Threads';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Відгуки';
    }

    public function getTitle(): string
    {
        return 'Відгук з Threads';
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

    public function setRating(int $rating): void
    {
        $this->rating = max(1, min(5, $rating));
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
            'threadsUrl' => trim($this->threadsUrl) ?: null,
            'photos' => $this->photos,
        ], [
            'authorName' => ['required', 'string', 'max:255'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['required', 'string', 'max:5000'],
            'threadsUrl' => ['nullable', 'url', 'max:2048'],
            'photos' => ['nullable', 'array', 'max:6'],
            'photos.*' => ['image', 'max:10240'],
        ])->validate();

        $exists = ProfileReview::query()
            ->where('profile_id', $this->profileId)
            ->where('author_name', $data['authorName'])
            ->where('body', $data['body'])
            ->exists();

        if ($exists) {
            Notification::make()->title('Такий відгук уже існує — пропущено')->warning()->send();

            return;
        }

        // Фото → publicDisk/reviews-media (той самий формат, що й відгуки з сайту).
        $mediaPaths = [];
        foreach ($this->photos as $photo) {
            $path = $photo->store('reviews-media', 'public');
            if ($path) {
                $mediaPaths[] = $path;
            }
        }

        // createQuietly: без обсервера (перерахунок робимо один раз нижче).
        $review = ProfileReview::createQuietly([
            'profile_id' => $this->profileId,
            'user_id' => null,
            'author_name' => $data['authorName'],
            'rating' => (int) $data['rating'],
            'body' => $data['body'],
            'media' => $mediaPaths ?: null,
            'status' => 'published',
            'published_at' => now(),
            'is_anonymous' => false,
            'external_source_type' => 'threads',
            'external_source_url' => $data['threadsUrl'],
            'external_review_author' => $data['authorName'],
            'external_review_date' => now()->toDateString(),
            'external_review_hash' => sha1($this->profileId.'|threads|'.$data['authorName'].'|'.$data['body']),
        ]);

        $commentsCount = $this->createComments($review);

        app(ProfileReviewStatsService::class)->recalculateForProfileId((int) $this->profileId);

        $this->lastReviewId = $review->id;
        $this->addedCount++;
        array_unshift($this->recent, [
            'author' => $data['authorName'],
            'rating' => (int) $data['rating'],
            'comments' => $commentsCount,
        ]);
        $this->recent = array_slice($this->recent, 0, 8);

        // Очищаємо поля відгуку, лишаємо профіль.
        $this->authorName = '';
        $this->body = '';
        $this->rating = 5;
        $this->threadsUrl = '';
        $this->comments = '';
        $this->photos = [];

        Notification::make()
            ->title('Відгук з Threads опубліковано'.($commentsCount ? " + {$commentsCount} комент." : ''))
            ->success()
            ->send();

        $this->dispatch('focus-author');
    }

    /**
     * Коментарі — по одному в рядку у форматі «Ім'я: текст» (ім'я необовʼязкове).
     * Кожен рядок → опублікований ReviewReply під відгуком.
     */
    private function createComments(ProfileReview $review): int
    {
        $lines = preg_split('/\r\n|\r|\n/', $this->comments) ?: [];
        $count = 0;

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $author = null;
            $text = $line;
            if (preg_match('/^(.{1,60}?):\s*(.+)$/u', $line, $m)) {
                $author = trim($m[1]);
                $text = trim($m[2]);
            }

            if ($text === '') {
                continue;
            }

            ReviewReply::create([
                'profile_review_id' => $review->id,
                'profile_id' => $this->profileId,
                'external_author' => $author ?: null,
                'body' => $text,
                'status' => 'published',
                'is_official' => false,
            ]);
            $count++;
        }

        return $count;
    }

    public function deleteLast(): void
    {
        if (! $this->lastReviewId) {
            return;
        }

        // Через модель, щоб обсервер перерахував рейтинг (коментарі підуть каскадом).
        ProfileReview::find($this->lastReviewId)?->delete();
        $this->lastReviewId = null;
        $this->addedCount = max(0, $this->addedCount - 1);
        array_shift($this->recent);
        $this->dispatch('focus-author');
    }
}
