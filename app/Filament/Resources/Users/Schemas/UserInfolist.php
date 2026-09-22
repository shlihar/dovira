<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Користувач')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('name')->label('Імʼя')->placeholder('—'),
                        TextEntry::make('email')->label('Email')->copyable(),
                        TextEntry::make('role')
                            ->label('Роль')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'admin' => 'danger',
                                'moderator', 'editor', 'support' => 'warning',
                                default => 'gray',
                            }),
                        TextEntry::make('status')
                            ->label('Статус')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'active' ? 'success' : 'danger'),
                        TextEntry::make('auth_provider')->label('Спосіб входу')->badge()->placeholder('email'),
                        IconEntry::make('email_verified_at')
                            ->label('Email підтверджено')
                            ->state(fn ($record): bool => $record->email_verified_at !== null)
                            ->boolean(),
                        TextEntry::make('telegram_username')->label('Telegram')->placeholder('—'),
                        TextEntry::make('email_notifications_enabled')
                            ->label('Email-сповіщення')
                            ->badge()
                            ->state(fn ($record): string => $record->email_notifications_enabled ? 'увімкнені' : 'вимкнені')
                            ->color(fn ($record): string => $record->email_notifications_enabled ? 'success' : 'gray'),
                        TextEntry::make('owned_profiles_count')->label('Профілів у власності')->badge()->color('primary'),
                        TextEntry::make('profile_claims_count')->label('Заявок на привʼязку')->badge()->color('gray'),
                        TextEntry::make('created_at')->label('Реєстрація')->dateTime('d.m.Y H:i'),
                        TextEntry::make('last_login_at')->label('Останній вхід')->dateTime('d.m.Y H:i')->placeholder('—'),
                    ]),
            ]);
    }
}
