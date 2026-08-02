<?php

namespace App\Filament\Resources\Profiles\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StatusHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'auditLogs';

    protected static ?string $title = 'Історія змін статусів';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('event')->label('Подія')->badge(),
                TextColumn::make('user.email')->label('Хто змінив')->placeholder('Система'),
                TextColumn::make('payload.status')->label('Новий статус')->placeholder('—'),
                TextColumn::make('created_at')->label('Дата')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
