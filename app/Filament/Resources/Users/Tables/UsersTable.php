<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->persistSortInSession()
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->width('1%')
                    ->grow(false)
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Імʼя')
                    ->searchable()
                    ->placeholder('—')
                    ->description(fn ($record) => $record->telegram_username ? '@' . $record->telegram_username : null)
                    ->limit(28),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable()
                    ->limit(32),
                TextColumn::make('role')
                    ->label('Роль')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'danger',
                        'moderator', 'editor', 'support' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'danger')
                    ->sortable(),
                TextColumn::make('auth_provider')
                    ->label('Вхід')
                    ->badge()
                    ->placeholder('email')
                    ->color('gray'),
                TextColumn::make('owned_profiles_count')
                    ->label('Профілів')
                    ->badge()
                    ->color(fn ($state): string => $state > 0 ? 'primary' : 'gray')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('profile_claims_count')
                    ->label('Заявок')
                    ->badge()
                    ->color('gray')
                    ->alignCenter()
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('email_verified_at')
                    ->label('Email ✓')
                    ->state(fn ($record): bool => $record->email_verified_at !== null)
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Реєстрація')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('last_login_at')
                    ->label('Останній вхід')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Роль')
                    ->options([
                        'user' => 'Користувач',
                        'support' => 'Підтримка',
                        'editor' => 'Редактор',
                        'moderator' => 'Модератор',
                        'admin' => 'Адміністратор',
                    ]),
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'active' => 'Активний',
                        'blocked' => 'Заблокований',
                    ]),
                SelectFilter::make('auth_provider')
                    ->label('Спосіб входу')
                    ->options([
                        'email' => 'Email',
                        'google' => 'Google',
                        'telegram' => 'Telegram',
                    ]),
                TernaryFilter::make('has_profiles')
                    ->label('Має профілі')
                    ->queries(
                        true: fn (Builder $query) => $query->has('ownedProfiles'),
                        false: fn (Builder $query) => $query->doesntHave('ownedProfiles'),
                        blank: fn (Builder $query) => $query,
                    ),
                TernaryFilter::make('email_verified')
                    ->label('Email підтверджено')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('email_verified_at'),
                        false: fn (Builder $query) => $query->whereNull('email_verified_at'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ]);
    }
}
