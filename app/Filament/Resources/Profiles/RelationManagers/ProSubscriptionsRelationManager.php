<?php

namespace App\Filament\Resources\Profiles\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProSubscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'proSubscriptions';

    protected static ?string $title = 'Власники / PRO-підписки профілю';

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('plan')
                ->label('Тариф')
                ->required()
                ->maxLength(255),
            Select::make('status')
                ->label('Статус')
                ->options([
                    'active' => 'active',
                    'paused' => 'paused',
                    'past_due' => 'past_due',
                    'payment_failed' => 'payment_failed',
                    'unpaid' => 'unpaid',
                    'expired' => 'expired',
                    'canceled' => 'canceled',
                ])
                ->required(),
            TextInput::make('currency')
                ->label('Валюта')
                ->default('UAH')
                ->maxLength(8),
            TextInput::make('price_monthly')
                ->label('Ціна / місяць')
                ->numeric()
                ->minValue(0),
            TextInput::make('price_yearly')
                ->label('Ціна / рік')
                ->numeric()
                ->minValue(0),
            DateTimePicker::make('started_at')
                ->label('Початок'),
            DateTimePicker::make('ends_at')
                ->label('Кінець'),
            DateTimePicker::make('canceled_at')
                ->label('Скасовано'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('plan')->label('Тариф')->badge(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'paused' => 'warning',
                        'past_due', 'payment_failed', 'unpaid' => 'danger',
                        'expired', 'canceled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('started_at')->label('Початок')->dateTime('d.m.Y'),
                TextColumn::make('ends_at')->label('Кінець')->dateTime('d.m.Y')->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
