<?php

namespace App\Filament\Resources\Reviews\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AuditLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'auditLogs';

    protected static ?string $title = 'Moderation History';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('event')->label('Event')->badge(),
                TextColumn::make('user.email')->label('Moderator')->placeholder('System'),
                TextColumn::make('payload.status')->label('Status')->placeholder('—'),
                TextColumn::make('created_at')->label('Created At')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
