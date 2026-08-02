<?php

namespace App\Filament\Resources\ProfileClaims\Tables;

use App\Services\ProfileClaimReviewService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProfileClaimsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'pending', 'need_more_info' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('profile.name')
                    ->label('Профіль')
                    ->searchable()
                    ->description(fn ($record) => $record->profile?->slug ?: '—')
                    ->limit(36),
                TextColumn::make('user.email')
                    ->label('Заявник')
                    ->searchable()
                    ->description(fn ($record) => $record->user?->name ?: '—')
                    ->limit(32),
                TextColumn::make('profile.owner.email')
                    ->label('Поточний власник')
                    ->placeholder('Не призначено')
                    ->limit(28),
                TextColumn::make('company_role')
                    ->label('Роль')
                    ->placeholder('—')
                    ->limit(22),
                IconColumn::make('profile.is_owner_verified')
                    ->label('Owner verified')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Створено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('reviewed_at')
                    ->label('Розглянуто')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'pending' => 'pending',
                        'need_more_info' => 'need_more_info',
                        'approved' => 'approved',
                        'rejected' => 'rejected',
                    ]),
                TernaryFilter::make('has_owner')
                    ->label('Є власник')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('profile', fn (Builder $inner) => $inner->whereNotNull('owner_user_id')),
                        false: fn (Builder $query) => $query->whereHas('profile', fn (Builder $inner) => $inner->whereNull('owner_user_id')),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('view')
                        ->label('Переглянути')
                        ->icon('heroicon-o-eye')
                        ->url(fn ($record) => route('filament.admin.resources.profile-claims.view', ['record' => $record])),
                    Action::make('open_profile')
                        ->label('Профіль')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn ($record) => route('filament.admin.resources.profiles.edit', ['record' => $record->profile_id])),
                    Action::make('approve')
                        ->label('Підтвердити')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn ($record) => $record->status !== 'approved')
                        ->action(function ($record, ProfileClaimReviewService $service): void {
                            $service->approve($record);

                            Notification::make()
                                ->title('Заявку підтверджено і профіль прив’язано')
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
                    Action::make('reject')
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
                ]),
            ]);
    }
}
