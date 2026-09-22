<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Обліковий запис')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Імʼя')
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        // password: на створенні обовʼязковий, на редагуванні —
                        // лишаєш порожнім, щоб не змінювати. Каст 'hashed' у моделі
                        // сам хешує при збереженні.
                        TextInput::make('password')
                            ->label('Пароль')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText('На редагуванні лишіть порожнім, щоб не змінювати.'),
                        Toggle::make('email_notifications_enabled')
                            ->label('Email-сповіщення увімкнені')
                            ->default(true),
                    ]),

                Section::make('Права та статус')
                    ->columns(2)
                    ->schema([
                        Select::make('role')
                            ->label('Роль')
                            ->options([
                                'user' => 'Користувач',
                                'support' => 'Підтримка',
                                'editor' => 'Редактор',
                                'moderator' => 'Модератор',
                                'admin' => 'Адміністратор',
                            ])
                            ->default('user')
                            ->required()
                            ->native(false)
                            // Роль (доступ до адмінки) міняє лише повний адмін.
                            ->disabled(fn (): bool => ! (Auth::user()?->hasAdminPermission('users.manage-roles') ?? false))
                            ->dehydrated(fn (): bool => Auth::user()?->hasAdminPermission('users.manage-roles') ?? false),
                        Select::make('status')
                            ->label('Статус')
                            ->options([
                                'active' => 'Активний',
                                'blocked' => 'Заблокований',
                            ])
                            ->default('active')
                            ->required()
                            ->native(false)
                            ->helperText('Заблокований користувач втрачає доступ до адмін-панелі.'),
                        Select::make('auth_provider')
                            ->label('Спосіб входу')
                            ->options([
                                'email' => 'Email + пароль',
                                'google' => 'Google',
                                'telegram' => 'Telegram',
                            ])
                            ->placeholder('—')
                            ->native(false),
                    ]),
            ]);
    }
}
