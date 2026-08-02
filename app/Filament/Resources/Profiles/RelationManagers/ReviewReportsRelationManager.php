<?php

namespace App\Filament\Resources\Profiles\RelationManagers;

use App\Models\ReviewReport;
use App\Services\ProProfileNotificationService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReviewReportsRelationManager extends RelationManager
{
    protected static string $relationship = 'reviewReports';

    protected static ?string $title = 'Скарги по профілю';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('review.id')->label('ID відгуку')->sortable(),
                TextColumn::make('review.status')
                    ->label('Стан відгуку')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'published' => 'success',
                        'hidden' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                TextColumn::make('reporter.email')->label('Хто подав')->placeholder('—'),
                TextColumn::make('reason')->label('Причина')->limit(36),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'resolved' => 'success',
                        'in_review' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')->label('Дата')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),

                // Рішення скарги керує долею відгуку: задоволена скарга знімає
                // відгук з сайту (status=rejected — лишається слід для аудиту),
                // відхилена — повертає відгук у публікацію, якщо власник його
                // приховав «до вирішення».
                Action::make('resolveRemoveReview')
                    ->label('Задовольнити: зняти відгук')
                    ->icon('heroicon-o-shield-check')
                    ->color('danger')
                    ->visible(fn (ReviewReport $record): bool => in_array($record->status, ['open', 'in_review'], true))
                    ->schema([
                        Textarea::make('moderator_note')
                            ->label('Нотатка модератора (необовʼязково)')
                            ->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading('Задовольнити скаргу і зняти відгук з сайту?')
                    ->modalDescription('Відгук отримає статус «відхилений», зникне з профілю, рейтинг перерахується автоматично.')
                    ->action(function (ReviewReport $record, array $data): void {
                        $this->resolveReport($record, 'resolved', (string) ($data['moderator_note'] ?? ''));

                        $review = $record->review;
                        if ($review && $review->status !== 'rejected') {
                            $review->update(['status' => 'rejected']);
                        }

                        $this->notifyOwner(
                            $record,
                            'Скаргу задоволено — відгук знято',
                            'Модерація розглянула скаргу і зняла відгук з публічної сторінки. Рейтинг перераховано.'
                        );

                        Notification::make()->title('Скаргу задоволено, відгук знято з сайту.')->success()->send();
                    }),

                Action::make('rejectRestoreReview')
                    ->label('Відхилити: повернути відгук')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (ReviewReport $record): bool => in_array($record->status, ['open', 'in_review'], true))
                    ->schema([
                        Textarea::make('moderator_note')
                            ->label('Нотатка модератора (необовʼязково)')
                            ->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading('Відхилити скаргу і повернути відгук?')
                    ->modalDescription('Скарга закриється як відхилена. Якщо власник приховав відгук на час розгляду — він знову стане опублікованим.')
                    ->action(function (ReviewReport $record, array $data): void {
                        $this->resolveReport($record, 'rejected', (string) ($data['moderator_note'] ?? ''));

                        $review = $record->review;
                        if ($review && $review->status === 'hidden') {
                            $review->update(['status' => 'published']);
                        }

                        $this->notifyOwner(
                            $record,
                            'Скаргу відхилено — відгук повернено',
                            'Модерація розглянула скаргу і не знайшла порушень. Відгук знову опубліковано на сторінці профілю.'
                        );

                        Notification::make()->title('Скаргу відхилено, відгук опубліковано.')->success()->send();
                    }),
            ]);
    }

    private function resolveReport(ReviewReport $record, string $status, string $note): void
    {
        $record->update([
            'status' => $status,
            'moderator_note' => trim($note) !== '' ? trim($note) : $record->moderator_note,
            'resolved_by_user_id' => auth()->id(),
            'resolved_at' => now(),
        ]);

        // Інші відкриті скарги на той самий відгук закриваємо тим самим
        // рішенням — щоб відгук не «висів» з відкритою скаргою після вердикту.
        ReviewReport::query()
            ->where('profile_review_id', $record->profile_review_id)
            ->whereKeyNot($record->id)
            ->whereIn('status', ['open', 'in_review'])
            ->update([
                'status' => $status,
                'resolved_by_user_id' => auth()->id(),
                'resolved_at' => now(),
            ]);
    }

    private function notifyOwner(ReviewReport $record, string $title, string $body): void
    {
        $profile = $record->review?->profile;

        if (! $profile || ! $profile->owner_user_id) {
            return;
        }

        app(ProProfileNotificationService::class)->create(
            $profile,
            'review_report_resolved',
            $title,
            $body,
            [
                'severity' => 'info',
                'action_url' => route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id]),
            ]
        );
    }
}
