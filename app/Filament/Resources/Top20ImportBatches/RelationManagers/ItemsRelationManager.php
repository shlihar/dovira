<?php

namespace App\Filament\Resources\Top20ImportBatches\RelationManagers;

use App\Filament\Resources\Profiles\ProfileResource;
use App\Models\Top20ImportItem;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Імпортовані профілі';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('raw_name')
                    ->label('Профіль')
                    ->searchable()
                    ->weight('semibold')
                    ->description(fn (Top20ImportItem $record) => implode(' · ', array_filter([
                        $record->raw_city ?: null,
                        $record->top20_url ?: null,
                    ])))
                    ->limit(48),
                TextColumn::make('profile.name')
                    ->label('Створений профіль')
                    ->placeholder('—')
                    ->searchable()
                    ->description(fn (Top20ImportItem $record) => $record->profile?->slug ?: 'Не створено')
                    ->limit(40),
                TextColumn::make('profile.status')
                    ->label('Статус профілю')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?string $state) => match ($state) {
                        'active' => 'success',
                        'hidden' => 'warning',
                        'draft' => 'gray',
                        'archived' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->label('Статус імпорту')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'processing' => 'info',
                        'pending' => 'gray',
                        'imported' => 'success',
                        'skipped' => 'warning',
                        'error' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('reviews_created')->label('Нові відгуки')->numeric(),
                TextColumn::make('reviews_updated')->label('Оновлені відгуки')->numeric(),
                TextColumn::make('processed_at')->label('Оброблено')->dateTime('d.m.Y H:i')->placeholder('—'),
                TextColumn::make('error_message')
                    ->label('Помилка')
                    ->limit(48)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус імпорту')
                    ->options([
                        'pending' => 'Очікує',
                        'processing' => 'В процесі',
                        'imported' => 'Імпортовано',
                        'skipped' => 'Пропущено',
                        'error' => 'Помилка',
                    ]),
                SelectFilter::make('profile_status')
                    ->label('Статус профілю')
                    ->options([
                        'draft' => 'Чернетка',
                        'active' => 'Опубліковано',
                        'hidden' => 'Приховано',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! $value) {
                            return $query;
                        }

                        return $query->whereHas('profile', fn (Builder $profileQuery) => $profileQuery->where('status', $value));
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('open_profile')
                        ->label('Відкрити профіль')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn (Top20ImportItem $record) => $record->profile_id
                            ? ProfileResource::getUrl('edit', ['record' => $record->profile_id])
                            : null)
                        ->visible(fn (Top20ImportItem $record) => filled($record->profile_id)),
                    Action::make('publish_profile')
                        ->label('Опублікувати')
                        ->icon('heroicon-o-eye')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (Top20ImportItem $record) => filled($record->profile_id))
                        ->action(function (Top20ImportItem $record): void {
                            $record->profile?->update([
                                'status' => 'active',
                                'is_published' => true,
                                'show_in_catalog' => true,
                            ]);

                            Notification::make()
                                ->title('Профіль опубліковано')
                                ->success()
                                ->send();
                        }),
                    Action::make('hide_profile')
                        ->label('Приховати')
                        ->icon('heroicon-o-eye-slash')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->visible(fn (Top20ImportItem $record) => filled($record->profile_id))
                        ->action(function (Top20ImportItem $record): void {
                            $record->profile?->update([
                                'status' => 'hidden',
                                'is_published' => false,
                                'show_in_catalog' => false,
                            ]);

                            Notification::make()
                                ->title('Профіль приховано')
                                ->warning()
                                ->send();
                        }),
                ])->label('Дії'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('publish_profiles')
                        ->label('Опублікувати вибрані профілі')
                        ->icon('heroicon-o-eye')
                        ->action(function (Collection $records): void {
                            $updated = 0;

                            $records->each(function (Top20ImportItem $record) use (&$updated): void {
                                if (! $record->profile) {
                                    return;
                                }

                                $record->profile->update([
                                    'status' => 'active',
                                    'is_published' => true,
                                    'show_in_catalog' => true,
                                ]);
                                $updated++;
                            });

                            Notification::make()
                                ->title("Опубліковано профілів: {$updated}")
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('hide_profiles')
                        ->label('Приховати вибрані профілі')
                        ->icon('heroicon-o-eye-slash')
                        ->action(function (Collection $records): void {
                            $updated = 0;

                            $records->each(function (Top20ImportItem $record) use (&$updated): void {
                                if (! $record->profile) {
                                    return;
                                }

                                $record->profile->update([
                                    'status' => 'hidden',
                                    'is_published' => false,
                                    'show_in_catalog' => false,
                                ]);
                                $updated++;
                            });

                            Notification::make()
                                ->title("Приховано профілів: {$updated}")
                                ->warning()
                                ->send();
                        }),
                ]),
            ]);
    }
}
