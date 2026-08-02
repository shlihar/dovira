<?php

namespace App\Filament\Widgets;

use App\Models\ProfileClaim;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LatestClaimsTableWidget extends TableWidget
{
    protected static ?int $sort = 5;

    protected int | string | array $columnSpan = [
        'xl' => 4,
    ];

    public function table(Table $table): Table
    {
        return $table
            ->heading('Останні заявки')
            ->query(
                ProfileClaim::query()
                    ->with(['user', 'profile'])
                    ->latest()
            )
            ->defaultPaginationPageOption(10)
            ->columns([
                TextColumn::make('user.name')
                    ->label('Хто подав')
                    ->default('Невідомо'),
                TextColumn::make('profile.name')
                    ->label('Профіль')
                    ->limit(26),
                TextColumn::make('company_role')
                    ->label('Тип заявки')
                    ->formatStateUsing(fn (?string $state) => $state ?: 'Claim profile'),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'pending', 'need_more_info' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->recordUrl(fn (ProfileClaim $record) => route('filament.admin.resources.profile-claims.view', ['record' => $record]))
            ->defaultSort('created_at', 'desc');
    }
}
