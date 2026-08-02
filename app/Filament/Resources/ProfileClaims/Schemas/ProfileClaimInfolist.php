<?php

namespace App\Filament\Resources\ProfileClaims\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProfileClaimInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Заявка')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('status')
                            ->label('Статус')
                            ->badge()
                            ->color(fn (string $state) => match ($state) {
                                'approved' => 'success',
                                'pending', 'need_more_info' => 'warning',
                                'rejected' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('profile.name')->label('Профіль'),
                        TextEntry::make('profile.slug')->label('Slug профілю')->placeholder('—'),
                        TextEntry::make('user.name')->label('Заявник')->placeholder('—'),
                        TextEntry::make('user.email')->label('Email заявника')->placeholder('—'),
                        TextEntry::make('profile.owner.email')->label('Поточний власник')->placeholder('Не призначено'),
                        TextEntry::make('company_role')->label('Роль у компанії')->placeholder('—'),
                        TextEntry::make('proof_document_url')
                            ->label('Підтвердження')
                            ->url(fn ($record) => $record->proof_document_url)
                            ->openUrlInNewTab()
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('note')->label('Коментар заявника')->columnSpanFull()->placeholder('—'),
                        IconEntry::make('profile.is_owner_verified')->label('Профіль уже verified')->boolean(),
                        TextEntry::make('created_at')->label('Створено')->dateTime('d.m.Y H:i'),
                        TextEntry::make('reviewer.name')->label('Перевірив')->placeholder('—'),
                        TextEntry::make('reviewed_at')->label('Розглянуто')->dateTime('d.m.Y H:i')->placeholder('—'),
                    ])
                    ->columns(3),
            ]);
    }
}
