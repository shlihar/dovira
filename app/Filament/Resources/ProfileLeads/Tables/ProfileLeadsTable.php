<?php

namespace App\Filament\Resources\ProfileLeads\Tables;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProfileLeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                IconColumn::make('is_read')
                    ->label('Прочитано')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'contacted' => 'success',
                        'archived' => 'gray',
                        'new' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Клієнт')
                    ->searchable()
                    ->limit(24),
                TextColumn::make('phone')
                    ->label('Телефон')
                    ->searchable()
                    ->copyable()
                    ->url(fn ($record) => 'tel:' . preg_replace('/[^0-9+]/', '', (string) $record->phone))
                    ->limit(22),
                TextColumn::make('profile.name')
                    ->label('Профіль')
                    ->searchable()
                    ->description(fn ($record) => $record->profile?->city ?: ($record->profile?->slug ?: '—'))
                    ->limit(36),
                TextColumn::make('profile.owner.email')
                    ->label('Власник')
                    ->placeholder('Не призначено')
                    ->limit(30),
                IconColumn::make('profile.is_pro')
                    ->label('PRO')
                    ->boolean(),
                TextColumn::make('message')
                    ->label('Повідомлення')
                    ->limit(48)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('source')
                    ->label('Джерело')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Створено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('responded_at')
                    ->label('Відповідь')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'new' => 'new',
                        'contacted' => 'contacted',
                        'archived' => 'archived',
                    ]),
                TernaryFilter::make('is_read')->label('Прочитано'),
                TernaryFilter::make('profile_is_pro')
                    ->label('PRO-профіль')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('profile', fn (Builder $inner) => $inner->where('is_pro', true)),
                        false: fn (Builder $query) => $query->whereHas('profile', fn (Builder $inner) => $inner->where('is_pro', false)),
                        blank: fn (Builder $query) => $query,
                    ),
                Filter::make('created_at')
                    ->label('Дата створення')
                    ->form([
                        DatePicker::make('from')->label('Від'),
                        DatePicker::make('until')->label('До'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('view')
                        ->label('Переглянути')
                        ->icon('heroicon-o-eye')
                        ->url(fn ($record) => route('filament.admin.resources.profile-leads.view', ['record' => $record])),
                    Action::make('open_profile')
                        ->label('Профіль')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn ($record) => route('filament.admin.resources.profiles.edit', ['record' => $record->profile_id])),
                    Action::make('mark_read')
                        ->label('Позначити прочитаною')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn ($record) => ! (bool) $record->is_read)
                        ->action(function ($record): void {
                            $record->update(['is_read' => true]);

                            Notification::make()
                                ->title('Заявку позначено прочитаною')
                                ->success()
                                ->send();
                        }),
                    Action::make('mark_unread')
                        ->label('Позначити непрочитаною')
                        ->icon('heroicon-o-envelope')
                        ->visible(fn ($record) => (bool) $record->is_read)
                        ->action(function ($record): void {
                            $record->update(['is_read' => false]);

                            Notification::make()
                                ->title('Заявку позначено непрочитаною')
                                ->success()
                                ->send();
                        }),
                    Action::make('mark_contacted')
                        ->label('Контакт встановлено')
                        ->icon('heroicon-o-phone')
                        ->color('success')
                        ->visible(fn ($record) => $record->status !== 'contacted')
                        ->action(function ($record): void {
                            $record->update([
                                'status' => 'contacted',
                                'is_read' => true,
                                'responded_at' => $record->responded_at ?: now(),
                            ]);

                            Notification::make()
                                ->title('Заявку позначено як опрацьовану')
                                ->success()
                                ->send();
                        }),
                    Action::make('archive')
                        ->label('Архівувати')
                        ->icon('heroicon-o-archive-box')
                        ->color('gray')
                        ->visible(fn ($record) => $record->status !== 'archived')
                        ->action(function ($record): void {
                            $record->update(['status' => 'archived', 'is_read' => true]);

                            Notification::make()
                                ->title('Заявку архівовано')
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->striped();
    }
}
