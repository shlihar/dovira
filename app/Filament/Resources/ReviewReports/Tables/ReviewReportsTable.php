<?php

namespace App\Filament\Resources\ReviewReports\Tables;

use App\Models\ReviewReport;
use App\Services\Moderation\ReviewReportModerator;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReviewReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('status')
                    ->label('Статус скарги')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'resolved' => 'success',
                        'in_review' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'open' => 'відкрита',
                        'in_review' => 'на розгляді',
                        'resolved' => 'задоволена',
                        'rejected' => 'відхилена',
                        default => $state,
                    })
                    ->sortable(),
                TextColumn::make('review.profile.name')
                    ->label('Профіль')
                    ->searchable()
                    ->limit(30)
                    ->url(fn (ReviewReport $record) => $record->review?->profile_id
                        ? route('filament.admin.resources.profiles.edit', ['record' => $record->review->profile_id])
                        : null),
                TextColumn::make('review.author_name')
                    ->label('Відгук')
                    ->placeholder('Анонім')
                    ->description(fn (ReviewReport $record) => $record->review
                        ? str_repeat('⭐', max(1, min(5, (int) $record->review->rating))).' · '.\Illuminate\Support\Str::limit((string) $record->review->body, 50)
                        : '—'),
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
                TextColumn::make('reason')->label('Причина')->limit(40)->wrap(),
                TextColumn::make('reporter.email')->label('Хто подав')->placeholder('—')->limit(24),
                TextColumn::make('created_at')->label('Подано')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'open' => 'відкрита',
                        'in_review' => 'на розгляді',
                        'resolved' => 'задоволена',
                        'rejected' => 'відхилена',
                    ])
                    ->default('open'),
            ])
            ->recordActions([
                ViewAction::make()->label('Деталі'),

                // Рішення скарги керує долею відгуку (див. ReviewReportModerator).
                Action::make('resolveRemoveReview')
                    ->label('Задовольнити: зняти відгук')
                    ->icon('heroicon-o-shield-check')
                    ->color('danger')
                    ->visible(fn (ReviewReport $record): bool => in_array($record->status, ['open', 'in_review'], true))
                    ->schema([
                        Textarea::make('moderator_note')->label('Нотатка модератора (необовʼязково)')->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading('Задовольнити скаргу і зняти відгук з сайту?')
                    ->modalDescription('Відгук отримає статус «відхилений», зникне з профілю, рейтинг перерахується автоматично.')
                    ->action(function (ReviewReport $record, array $data, ReviewReportModerator $moderator): void {
                        $moderator->resolve($record, (string) ($data['moderator_note'] ?? ''), auth()->id());
                        Notification::make()->title('Скаргу задоволено, відгук знято з сайту.')->success()->send();
                    }),

                Action::make('rejectRestoreReview')
                    ->label('Відхилити: повернути відгук')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (ReviewReport $record): bool => in_array($record->status, ['open', 'in_review'], true))
                    ->schema([
                        Textarea::make('moderator_note')->label('Нотатка модератора (необовʼязково)')->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading('Відхилити скаргу і повернути відгук?')
                    ->modalDescription('Скарга закриється як відхилена. Якщо власник приховав відгук на час розгляду — він знову стане опублікованим.')
                    ->action(function (ReviewReport $record, array $data, ReviewReportModerator $moderator): void {
                        $moderator->reject($record, (string) ($data['moderator_note'] ?? ''), auth()->id());
                        Notification::make()->title('Скаргу відхилено, відгук опубліковано.')->success()->send();
                    }),

                ActionGroup::make([
                    Action::make('open_review_profile')
                        ->label('Відкрити профіль')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn (ReviewReport $record) => $record->review?->profile?->slug
                            ? route('profile.show', ['slug' => $record->review->profile->slug])
                            : null)
                        ->openUrlInNewTab()
                        ->visible(fn (ReviewReport $record) => (bool) $record->review?->profile?->slug),
                ]),
            ]);
    }
}
