<?php

namespace App\Filament\Resources\Reviews\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReportsRelationManager extends RelationManager
{
    protected static string $relationship = 'reports';

    protected static ?string $title = 'Reports';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID'),
                TextColumn::make('reporter.email')->label('Reporter')->placeholder('—'),
                TextColumn::make('reason')->label('Reason'),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('created_at')->label('Created')->dateTime('Y-m-d H:i'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
