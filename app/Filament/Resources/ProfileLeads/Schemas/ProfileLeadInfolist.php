<?php

namespace App\Filament\Resources\ProfileLeads\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProfileLeadInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Заявка клієнта')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('status')
                            ->label('Статус')
                            ->badge()
                            ->color(fn (?string $state) => match ($state) {
                                'contacted' => 'success',
                                'archived' => 'gray',
                                'new' => 'warning',
                                default => 'gray',
                            }),
                        IconEntry::make('is_read')->label('Прочитано')->boolean(),
                        TextEntry::make('name')->label('Імʼя клієнта'),
                        TextEntry::make('phone')
                            ->label('Телефон')
                            ->url(fn ($record) => 'tel:' . preg_replace('/[^0-9+]/', '', (string) $record->phone)),
                        TextEntry::make('message')
                            ->label('Повідомлення')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('source')->label('Джерело')->placeholder('—'),
                        TextEntry::make('created_at')->label('Створено')->dateTime('d.m.Y H:i'),
                        TextEntry::make('responded_at')->label('Перша відповідь')->dateTime('d.m.Y H:i')->placeholder('—'),
                    ])
                    ->columns(3),
                Section::make('Профіль')
                    ->schema([
                        TextEntry::make('profile.name')->label('Профіль')->placeholder('—'),
                        TextEntry::make('profile.slug')->label('Slug')->placeholder('—'),
                        TextEntry::make('profile.city')->label('Місто')->placeholder('—'),
                        TextEntry::make('profile.owner.email')->label('Власник')->placeholder('Не призначено'),
                        TextEntry::make('profile.is_pro')
                            ->label('PRO')
                            ->badge()
                            ->state(fn ($record) => $record->profile?->is_pro ? 'Так' : 'Ні')
                            ->color(fn (string $state) => $state === 'Так' ? 'success' : 'gray'),
                    ])
                    ->columns(3),
            ]);
    }
}
