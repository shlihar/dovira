<?php

namespace App\Filament\Resources\Categories\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class FiltersRelationManager extends RelationManager
{
    protected static string $relationship = 'filters';

    protected static ?string $title = 'Filters';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label('Name')->searchable()->weight('semibold'),
                TextColumn::make('slug')->label('Slug')->searchable()->copyable(),
                TextColumn::make('type')->label('Type')->badge(),
                TextColumn::make('unit')->label('Unit')->placeholder('—'),
                IconColumn::make('is_active')->label('Active')->boolean(),
                IconColumn::make('is_required')->label('Required')->boolean(),
                IconColumn::make('show_in_catalog')->label('Show in Catalog')->boolean(),
                TextColumn::make('sort_order')->label('Sort')->numeric()->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->form([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Str::slug((string) $state))),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255),
                        Select::make('type')
                            ->required()
                            ->options([
                                'select' => 'select',
                                'multiselect' => 'multiselect',
                                'checkbox' => 'checkbox',
                                'boolean' => 'boolean',
                                'range' => 'range',
                                'text' => 'text',
                                'number' => 'number',
                                'date' => 'date',
                            ]),
                        KeyValue::make('options')
                            ->label('Options')
                            ->keyLabel('Key')
                            ->valueLabel('Value')
                            ->columnSpanFull(),
                        TextInput::make('unit')->maxLength(64),
                        TextInput::make('sort_order')->numeric()->default(0),
                        Toggle::make('is_active')->default(true),
                        Toggle::make('is_required')->default(false),
                        Toggle::make('show_in_catalog')->default(true),
                    ])
                    ->slideOver(),
            ])
            ->recordActions([
                EditAction::make()
                    ->form([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('slug')->required()->maxLength(255),
                        Select::make('type')
                            ->required()
                            ->options([
                                'select' => 'select',
                                'multiselect' => 'multiselect',
                                'checkbox' => 'checkbox',
                                'boolean' => 'boolean',
                                'range' => 'range',
                                'text' => 'text',
                                'number' => 'number',
                                'date' => 'date',
                            ]),
                        KeyValue::make('options')
                            ->label('Options')
                            ->keyLabel('Key')
                            ->valueLabel('Value')
                            ->columnSpanFull(),
                        TextInput::make('unit')->maxLength(64),
                        TextInput::make('sort_order')->numeric()->default(0),
                        Toggle::make('is_active'),
                        Toggle::make('is_required'),
                        Toggle::make('show_in_catalog'),
                    ])
                    ->slideOver(),
                DeleteAction::make(),
            ]);
    }
}
