<?php

namespace App\Filament\Support;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;

class AnalyticsFiltersSchema
{
    public static function make(string $periodField = 'period', string $startField = 'startDate', string $endField = 'endDate'): Section
    {
        return Section::make('Фільтри аналітики')
            ->description('Період впливає на KPI та графіки. За замовчуванням: 7 днів.')
            ->columnSpanFull()
            ->schema([
                Select::make($periodField)
                    ->label('Період')
                    ->options([
                        'today' => 'Сьогодні',
                        'last_7' => 'Останні 7 днів',
                        'last_30' => 'Останні 30 днів',
                        'last_90' => 'Останні 90 днів',
                        'all_time' => 'Весь час',
                        'custom' => 'Кастомний період',
                    ])
                    ->default('last_7')
                    ->native(true)
                    ->live(),
                DatePicker::make($startField)
                    ->label('Початкова дата')
                    ->native(true)
                    ->live(),
                DatePicker::make($endField)
                    ->label('Кінцева дата')
                    ->native(true)
                    ->live(),
            ])
            ->columns([
                'default' => 1,
                'md' => 3,
            ]);
    }
}

