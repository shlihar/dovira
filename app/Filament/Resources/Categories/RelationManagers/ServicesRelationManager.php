<?php

namespace App\Filament\Resources\Categories\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ServicesRelationManager extends RelationManager
{
    protected static string $relationship = 'services';

    protected static ?string $title = 'Напрями / послуги';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label('Назва')->searchable()->weight('semibold'),
                TextColumn::make('slug')->label('Slug')->copyable(),
                TextColumn::make('profiles_count')->label('Профілі')->numeric(),
                TextColumn::make('reviews_count')->label('Відгуки')->numeric(),
                TextColumn::make('views_count')->label('Перегляди')->numeric(),
                TextColumn::make('pro_profiles_count')->label('PRO')->numeric(),
                IconColumn::make('is_active')->label('Активний')->boolean(),
                IconColumn::make('show_in_catalog')->label('У каталозі')->boolean(),
                TextColumn::make('sort_order')->label('Порядок')->numeric()->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Додати напрям')
                    ->form([
                        TextInput::make('name')
                            ->label('Назва')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Str::slug((string) $state))),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('description')
                            ->label('Опис')
                            ->rows(3),
                        TextInput::make('sort_order')
                            ->label('Порядок')
                            ->numeric()
                            ->default(0),
                        Toggle::make('is_active')
                            ->label('Активний')
                            ->default(true),
                        Toggle::make('show_in_catalog')
                            ->label('Показувати в каталозі')
                            ->default(true),
                    ])
                    ->slideOver(),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Редагувати')
                    ->form([
                        TextInput::make('name')
                            ->label('Назва')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('description')
                            ->label('Опис')
                            ->rows(3),
                        TextInput::make('sort_order')
                            ->label('Порядок')
                            ->numeric()
                            ->default(0),
                        Toggle::make('is_active')
                            ->label('Активний'),
                        Toggle::make('show_in_catalog')
                            ->label('Показувати в каталозі'),
                    ])
                    ->slideOver(),
                DeleteAction::make(),
            ]);
    }
}

