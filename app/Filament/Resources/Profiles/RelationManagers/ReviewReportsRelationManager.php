<?php

namespace App\Filament\Resources\Profiles\RelationManagers;

use App\Models\ReviewReport;
use App\Services\Moderation\ReviewReportModerator;
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
                        Textarea::make('moderator_note')
                            ->label('Нотатка модератора (необовʼязково)')
                            ->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading('Відхилити скаргу і повернути відгук?')
                    ->modalDescription('Скарга закриється як відхилена. Якщо власник приховав відгук на час розгляду — він знову стане опублікованим.')
                    ->action(function (ReviewReport $record, array $data, ReviewReportModerator $moderator): void {
                        $moderator->reject($record, (string) ($data['moderator_note'] ?? ''), auth()->id());

                        Notification::make()->title('Скаргу відхилено, відгук опубліковано.')->success()->send();
                    }),
            ]);
    }
}
