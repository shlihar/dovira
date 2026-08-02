<?php

namespace App\Filament\Resources\Reviews\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RepliesRelationManager extends RelationManager
{
    protected static string $relationship = 'replies';

    protected static ?string $title = 'Коментарі до відгуку';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('author.email')->label('Автор')->placeholder('Адмін / профіль')->searchable(),
                TextColumn::make('parent_id')->label('Parent')->placeholder('—')->sortable(),
                TextColumn::make('body')->label('Коментар')->limit(120)->wrap()->searchable(),
                IconColumn::make('is_official')->label('Офіційний')->boolean(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'published' => 'success',
                        'hidden' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')->label('Створено')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'published' => 'published',
                        'pending' => 'pending',
                        'hidden' => 'hidden',
                    ]),
            ])
            ->headerActions([
                CreateAction::make()
                    ->form([
                        Hidden::make('profile_id')
                            ->default(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->profile_id),
                        Select::make('author_user_id')
                            ->label('Автор')
                            ->relationship('author', 'email')
                            ->searchable()
                            ->preload(),
                        Select::make('parent_id')
                            ->label('Parent comment')
                            ->options(fn (RelationManager $livewire) => $livewire->getOwnerRecord()
                                ->replies()
                                ->whereNull('parent_id')
                                ->pluck('body', 'id')
                                ->map(fn (string $body) => str($body)->limit(80)->toString())
                                ->all())
                            ->searchable(),
                        Select::make('status')
                            ->label('Статус')
                            ->options([
                                'published' => 'published',
                                'pending' => 'pending',
                                'hidden' => 'hidden',
                            ])
                            ->default('published')
                            ->required(),
                        Toggle::make('is_official')->label('Офіційний коментар')->default(false),
                        Textarea::make('body')->required()->rows(4)->columnSpanFull(),
                    ]),
            ])
            ->recordActions([
                EditAction::make()->form([
                    Select::make('author_user_id')
                        ->label('Автор')
                        ->relationship('author', 'email')
                        ->searchable()
                        ->preload(),
                    Select::make('parent_id')
                        ->label('Parent comment')
                        ->options(fn (RelationManager $livewire) => $livewire->getOwnerRecord()
                            ->replies()
                            ->whereNull('parent_id')
                            ->pluck('body', 'id')
                            ->map(fn (string $body) => str($body)->limit(80)->toString())
                            ->all())
                        ->searchable(),
                    Select::make('status')
                        ->label('Статус')
                        ->options([
                            'published' => 'published',
                            'pending' => 'pending',
                            'hidden' => 'hidden',
                        ])
                        ->required(),
                    Toggle::make('is_official')->label('Офіційний коментар'),
                    Textarea::make('body')->required()->rows(4)->columnSpanFull(),
                ]),
                DeleteAction::make(),
            ]);
    }
}
