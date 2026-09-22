<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Services\ProfileClaimReviewService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ClaimsRelationManager extends RelationManager
{
    protected static string $relationship = 'profileClaims';

    protected static ?string $title = 'Заявки на привʼязку';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-identification';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('profile.name')
                    ->label('Профіль')
                    ->description(fn ($record) => $record->profile?->slug ?: '—')
                    ->limit(36),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'pending', 'need_more_info' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('company_role')->label('Роль')->placeholder('—')->limit(22),
                TextColumn::make('created_at')->label('Створено')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('reviewed_at')->label('Розглянуто')->dateTime('d.m.Y H:i')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('open_profile')
                    ->label('Профіль')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record): string => route('filament.admin.resources.profiles.edit', ['record' => $record->profile_id]))
                    ->openUrlInNewTab(),
                Action::make('approve')
                    ->label('Підтвердити')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn ($record): bool => $record->status !== 'approved')
                    ->requiresConfirmation()
                    ->modalDescription('Профіль буде привʼязано до цього користувача.')
                    ->action(function ($record, ProfileClaimReviewService $service): void {
                        $service->approve($record);

                        Notification::make()
                            ->title('Заявку підтверджено і профіль привʼязано')
                            ->success()
                            ->send();
                    }),
                Action::make('reject')
                    ->label('Відхилити')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn ($record): bool => $record->status !== 'rejected')
                    ->action(function ($record, ProfileClaimReviewService $service): void {
                        $service->reject($record);

                        Notification::make()
                            ->title('Заявку відхилено')
                            ->danger()
                            ->send();
                    }),
            ]);
    }
}
