<?php

namespace App\Filament\Widgets;

use App\Models\ProfileReview;
use Filament\Actions\Action;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LatestReviewsTableWidget extends TableWidget
{
    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = [
        'xl' => 8,
    ];

    public function table(Table $table): Table
    {
        return $table
            ->heading('Останні відгуки')
            ->query(
                ProfileReview::query()
                    ->with('profile')
                    ->latest()
            )
            ->defaultPaginationPageOption(10)
            ->columns([
                TextColumn::make('author_name')
                    ->label('Автор')
                    ->searchable()
                    ->default('Користувач DOVIRA'),
                TextColumn::make('profile.name')
                    ->label('Профіль')
                    ->searchable(),
                TextColumn::make('rating')
                    ->label('Рейтинг')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 1)),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'pending', 'under_review' => 'warning',
                        'rejected', 'hidden' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->actions([
                Action::make('view_profile')
                    ->label('Переглянути')
                    ->icon('heroicon-m-eye')
                    ->url(fn (ProfileReview $record) => $record->profile?->slug ? route('profile.show', $record->profile->slug) : null)
                    ->openUrlInNewTab(),
                Action::make('moderate')
                    ->label('Модерувати')
                    ->icon('heroicon-m-shield-check')
                    ->color('warning')
                    ->url(fn (ProfileReview $record) => route('admin.reviews.moderation', ['review' => $record->id]))
                    ->visible(fn () => \Illuminate\Support\Facades\Route::has('admin.reviews.moderation')),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
