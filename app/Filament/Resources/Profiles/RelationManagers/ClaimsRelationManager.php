<?php

namespace App\Filament\Resources\Profiles\RelationManagers;

use App\Services\ProfileClaimReviewService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ClaimsRelationManager extends RelationManager
{
    protected static string $relationship = 'claims';

    protected static ?string $title = 'Заявки на підтвердження';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.email')->label('Хто подав')->searchable(),
                TextColumn::make('company_role')->label('Посада')->placeholder('—'),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'pending', 'need_more_info' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')->label('Дата')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('approve_claim')
                    ->label('Підтвердити')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn ($record) => $record->status !== 'approved')
                    ->action(function ($record, ProfileClaimReviewService $service): void {
                        $service->approve($record);

                        Notification::make()
                            ->title('Заявку підтверджено і профіль прив’язано до власника')
                            ->success()
                            ->send();
                    }),
                Action::make('need_more_info')
                    ->label('Потребує уточнення')
                    ->icon('heroicon-o-information-circle')
                    ->color('warning')
                    ->visible(fn ($record) => $record->status !== 'need_more_info')
                    ->action(function ($record, ProfileClaimReviewService $service): void {
                        $service->markNeedMoreInfo($record);

                        Notification::make()
                            ->title('Статус змінено: потребує уточнення')
                            ->warning()
                            ->send();
                    }),
                Action::make('reject_claim')
                    ->label('Відхилити')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status !== 'rejected')
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
