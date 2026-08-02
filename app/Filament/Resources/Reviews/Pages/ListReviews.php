<?php

namespace App\Filament\Resources\Reviews\Pages;

use App\Filament\Resources\Reviews\ReviewResource;
use App\Models\Profile;
use App\Models\ProfileReview;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListReviews extends ListRecords
{
    protected static string $resource = ReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('exportFiltered')
                ->label('Експорт за фільтрами')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->exportFilteredReviews()),
            Action::make('importCsv')
                ->label('Імпорт CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->form([
                    FileUpload::make('csv_file')
                        ->label('CSV файл')
                        ->acceptedFileTypes([
                            'text/csv',
                            'text/plain',
                            'application/vnd.ms-excel',
                            'text/comma-separated-values',
                        ])
                        ->disk('local')
                        ->directory('imports/reviews')
                        ->required(),
                    Select::make('mode')
                        ->label('Режим імпорту')
                        ->options([
                            'upsert' => 'Оновити існуючі + створити нові',
                            'create' => 'Тільки створити нові',
                            'update' => 'Тільки оновити існуючі',
                        ])
                        ->default('upsert')
                        ->required(),
                    Select::make('delimiter')
                        ->label('Розділювач')
                        ->options([
                            ',' => 'Кома (,)',
                            ';' => 'Крапка з комою (;)',
                            "\t" => 'Табуляція',
                        ])
                        ->default(',')
                        ->required(),
                    Toggle::make('has_header')
                        ->label('Перший рядок містить заголовки')
                        ->default(true),
                ])
                ->action(function (array $data): void {
                    $path = is_array($data['csv_file']) ? Arr::first($data['csv_file']) : $data['csv_file'];

                    $this->importReviewsFromCsv(
                        (string) $path,
                        (string) $data['mode'],
                        (string) $data['delimiter'],
                        (bool) $data['has_header'],
                    );
                }),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'pending' => Tab::make('Pending')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),
            'published' => Tab::make('Published')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'published')),
            'rejected' => Tab::make('Rejected')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'rejected')),
            'hidden' => Tab::make('Hidden')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'hidden')),
            'with_reports' => Tab::make('With Reports')->modifyQueryUsing(fn (Builder $query) => $query->has('reports')),
            'verified' => Tab::make('Verified')->modifyQueryUsing(fn (Builder $query) => $query->where('is_verified_purchase', true)),
            'suspicious' => Tab::make('Suspicious')->modifyQueryUsing(fn (Builder $query) => $query->where('is_suspicious', true)),
        ];
    }

    protected function exportFilteredReviews(): StreamedResponse
    {
        $filename = 'reviews-filtered-export-' . now()->format('Ymd-His') . '.csv';
        $query = $this->getFilteredSortedTableQuery()->with(['profile:id,name,slug', 'author:id,name,email']);

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'ID',
                'Profile ID',
                'Profile Slug',
                'Profile Name',
                'User ID',
                'Author Name',
                'Author Email',
                'Rating',
                'Title',
                'Body',
                'Status',
                'Is Verified',
                'Is Anonymous',
                'Is Suspicious',
                'Is Featured',
                'Verification Type',
                'Admin Note',
                'Moderation Reason',
                'Created At',
            ]);

            foreach ($query->cursor() as $review) {
                fputcsv($handle, [
                    $review->id,
                    $review->profile_id,
                    $review->profile?->slug,
                    $review->profile?->name,
                    $review->user_id,
                    $review->author_name,
                    $review->author_email,
                    $review->rating,
                    $review->title,
                    $review->body,
                    $review->status,
                    $review->is_verified_purchase ? '1' : '0',
                    $review->is_anonymous ? '1' : '0',
                    $review->is_suspicious ? '1' : '0',
                    $review->is_featured ? '1' : '0',
                    $review->verification_type,
                    $review->admin_note,
                    $review->moderation_reason,
                    optional($review->created_at)->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($handle);
        }, $filename);
    }

    protected function importReviewsFromCsv(string $path, string $mode, string $delimiter, bool $hasHeader): void
    {
        $absolutePath = Storage::disk('local')->path($path);

        if (! is_file($absolutePath)) {
            Notification::make()->title('Файл не знайдено')->danger()->send();
            return;
        }

        $handle = fopen($absolutePath, 'r');

        if (! $handle) {
            Notification::make()->title('Не вдалося відкрити CSV')->danger()->send();
            return;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;
        $headerMap = [];

        if ($hasHeader) {
            $headerMap = $this->buildHeaderMap(fgetcsv($handle, 0, $delimiter) ?: []);
        }

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (! $this->rowHasContent($row)) {
                continue;
            }

            try {
                $payload = $hasHeader
                    ? $this->mapRowByHeader($row, $headerMap)
                    : $this->mapRowByDefaultOrder($row);

                $result = $this->upsertReviewRow($payload, $mode);
                match ($result) {
                    'created' => $created++,
                    'updated' => $updated++,
                    'skipped' => $skipped++,
                    default => $skipped++,
                };
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        fclose($handle);

        Notification::make()
            ->title('Імпорт відгуків завершено')
            ->body("Створено: {$created}, Оновлено: {$updated}, Пропущено: {$skipped}, Помилки: {$errors}")
            ->success()
            ->send();
    }

    protected function buildHeaderMap(array $headerRow): array
    {
        $aliases = [
            'id' => 'id',
            'profile_id' => 'profile_id',
            'profile slug' => 'profile_slug',
            'profile_slug' => 'profile_slug',
            'profile name' => 'profile_name',
            'profile_name' => 'profile_name',
            'user_id' => 'user_id',
            'author' => 'author_name',
            'author_name' => 'author_name',
            'author email' => 'author_email',
            'author_email' => 'author_email',
            'rating' => 'rating',
            'title' => 'title',
            'text' => 'body',
            'body' => 'body',
            'status' => 'status',
            'is verified' => 'is_verified_purchase',
            'is_verified' => 'is_verified_purchase',
            'is anonymous' => 'is_anonymous',
            'is_anonymous' => 'is_anonymous',
            'is suspicious' => 'is_suspicious',
            'is_suspicious' => 'is_suspicious',
            'is featured' => 'is_featured',
            'is_featured' => 'is_featured',
            'verification type' => 'verification_type',
            'verification_type' => 'verification_type',
            'admin note' => 'admin_note',
            'admin_note' => 'admin_note',
            'moderation reason' => 'moderation_reason',
            'moderation_reason' => 'moderation_reason',
        ];

        $map = [];
        foreach ($headerRow as $i => $raw) {
            $key = Str::of((string) $raw)->lower()->trim()->replace(['"', "'", '`'], '')->toString();
            if (array_key_exists($key, $aliases)) {
                $map[$i] = $aliases[$key];
            }
        }

        return $map;
    }

    protected function mapRowByHeader(array $row, array $headerMap): array
    {
        $payload = [];
        foreach ($headerMap as $index => $field) {
            $payload[$field] = trim((string) ($row[$index] ?? ''));
        }

        return $payload;
    }

    protected function mapRowByDefaultOrder(array $row): array
    {
        return [
            'id' => trim((string) ($row[0] ?? '')),
            'profile_id' => trim((string) ($row[1] ?? '')),
            'profile_slug' => trim((string) ($row[2] ?? '')),
            'author_name' => trim((string) ($row[3] ?? '')),
            'author_email' => trim((string) ($row[4] ?? '')),
            'rating' => trim((string) ($row[5] ?? '')),
            'title' => trim((string) ($row[6] ?? '')),
            'body' => trim((string) ($row[7] ?? '')),
            'status' => trim((string) ($row[8] ?? '')),
            'is_verified_purchase' => trim((string) ($row[9] ?? '')),
            'is_anonymous' => trim((string) ($row[10] ?? '')),
            'is_suspicious' => trim((string) ($row[11] ?? '')),
            'verification_type' => trim((string) ($row[12] ?? '')),
        ];
    }

    protected function upsertReviewRow(array $payload, string $mode): string
    {
        $profileId = $this->resolveProfileId($payload);
        if (! $profileId) {
            return 'skipped';
        }

        $userId = $this->resolveUserId($payload);
        $rating = max(1, min(5, (int) ($payload['rating'] ?? 0)));
        $body = trim((string) ($payload['body'] ?? ''));

        if (! $rating || $body === '') {
            return 'skipped';
        }

        $existing = null;
        if (! empty($payload['id'])) {
            $existing = ProfileReview::query()->find((int) $payload['id']);
        }

        if (! $existing) {
            $existing = ProfileReview::query()
                ->where('profile_id', $profileId)
                ->where('author_email', (string) ($payload['author_email'] ?? ''))
                ->where('body', $body)
                ->first();
        }

        if ($mode === 'create' && $existing) {
            return 'skipped';
        }
        if ($mode === 'update' && ! $existing) {
            return 'skipped';
        }

        $review = $existing ?: new ProfileReview();
        $isNew = ! $existing;

        $status = in_array(($payload['status'] ?? ''), ['pending', 'published', 'rejected', 'hidden', 'under_review'], true)
            ? $payload['status']
            : ($review->status ?: 'pending');

        $review->fill([
            'profile_id' => $profileId,
            'user_id' => $userId,
            'author_name' => $payload['author_name'] ?? $review->author_name,
            'author_email' => $payload['author_email'] ?? $review->author_email,
            'rating' => $rating,
            'title' => $payload['title'] ?? $review->title,
            'body' => $body,
            'status' => $status,
            'is_verified_purchase' => array_key_exists('is_verified_purchase', $payload)
                ? $this->toBool($payload['is_verified_purchase'])
                : $review->is_verified_purchase,
            'is_anonymous' => array_key_exists('is_anonymous', $payload)
                ? $this->toBool($payload['is_anonymous'])
                : $review->is_anonymous,
            'is_suspicious' => array_key_exists('is_suspicious', $payload)
                ? $this->toBool($payload['is_suspicious'])
                : $review->is_suspicious,
            'is_featured' => array_key_exists('is_featured', $payload)
                ? $this->toBool($payload['is_featured'])
                : $review->is_featured,
            'verification_type' => $payload['verification_type'] ?? $review->verification_type,
            'admin_note' => $payload['admin_note'] ?? $review->admin_note,
            'moderation_reason' => $payload['moderation_reason'] ?? $review->moderation_reason,
            'published_at' => $status === 'published' ? ($review->published_at ?? now()) : null,
        ]);

        $review->save();

        return $isNew ? 'created' : 'updated';
    }

    protected function resolveProfileId(array $payload): ?int
    {
        if (! empty($payload['profile_id']) && is_numeric($payload['profile_id'])) {
            $id = (int) $payload['profile_id'];
            if (Profile::query()->whereKey($id)->exists()) {
                return $id;
            }
        }

        if (! empty($payload['profile_slug'])) {
            $id = Profile::query()->where('slug', $payload['profile_slug'])->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        if (! empty($payload['profile_name'])) {
            $id = Profile::query()->where('name', $payload['profile_name'])->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        return null;
    }

    protected function resolveUserId(array $payload): ?int
    {
        if (! empty($payload['user_id']) && is_numeric($payload['user_id'])) {
            $id = (int) $payload['user_id'];
            if (User::query()->whereKey($id)->exists()) {
                return $id;
            }
        }

        if (! empty($payload['author_email'])) {
            $id = User::query()->where('email', $payload['author_email'])->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        return null;
    }

    protected function toBool(string|int|bool|null $value): bool
    {
        return in_array(Str::lower(trim((string) $value)), ['1', 'true', 'yes', 'y', 'так'], true);
    }

    protected function rowHasContent(array $row): bool
    {
        foreach ($row as $column) {
            if (trim((string) $column) !== '') {
                return true;
            }
        }

        return false;
    }
}
