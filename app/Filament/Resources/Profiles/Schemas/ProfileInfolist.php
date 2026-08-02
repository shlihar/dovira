<?php

namespace App\Filament\Resources\Profiles\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProfileInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Профіль')
                ->schema([
                    TextEntry::make('id')->label('ID'),
                    TextEntry::make('name')->label('Назва профілю'),
                    TextEntry::make('slug')->label('Slug'),
                    TextEntry::make('type')->label('Тип'),
                    TextEntry::make('region.name')->label('Регіон')->placeholder('—'),
                    TextEntry::make('city')->label('Місто')->placeholder('—'),
                    TextEntry::make('rating_avg')->label('Рейтинг')->numeric(decimalPlaces: 1),
                    TextEntry::make('reviews_count')->label('Відгуки')->numeric(),
                    TextEntry::make('completeness')
                        ->label('Повнота профілю')
                        ->state(fn ($record) => $record->completenessPercent() . '%'),
                    IconEntry::make('is_verified')->label('Verified')->boolean(),
                    IconEntry::make('owner_verified_status')
                        ->label('Синя галочка')
                        ->boolean()
                        ->state(fn ($record) => (bool) ($record->is_owner_verified || $record->has_approved_claim)),
                    IconEntry::make('is_pro')->label('PRO')->boolean(),
                    TextEntry::make('status')
                        ->label('Статус')
                        ->badge(),
                    TextEntry::make('created_at')->label('Дата створення')->dateTime('d.m.Y H:i'),
                    TextEntry::make('updated_at')->label('Оновлено')->dateTime('d.m.Y H:i'),
                ])
                ->columns(3),
        ]);
    }
}
