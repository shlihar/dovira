<?php

namespace App\Filament\Resources\Reviews\Tables;

use App\Models\Category;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReviewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('author_name')
                    ->label('Author')
                    ->searchable()
                    ->description(fn ($record) => $record->author_email ?: '—')
                    ->limit(24),
                TextColumn::make('profile.name')
                    ->label('Profile')
                    ->searchable()
                    ->limit(32),
                TextColumn::make('category')
                    ->label('Category')
                    ->state(fn ($record) => optional($record->profile?->categories()->wherePivot('is_primary', true)->first())->name ?? '—'),
                TextColumn::make('rating')
                    ->label('Rating')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'published' => 'success',
                        'pending', 'under_review' => 'warning',
                        'rejected', 'hidden' => 'danger',
                        default => 'gray',
                    }),
                IconColumn::make('is_verified_purchase')->label('Is Verified')->boolean(),
                IconColumn::make('is_anonymous')->label('Is Anonymous')->boolean(),
                IconColumn::make('is_suspicious')->label('Is Suspicious')->boolean(),
                TextColumn::make('external_source_type')
                    ->label('Source')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'google', 'google_maps', 'google_business' => 'Google',
                        null, '' => 'DOVIRA',
                        default => $state,
                    })
                    ->placeholder('DOVIRA')
                    ->color(fn (?string $state) => filled($state) ? 'info' : 'gray')
                    ->toggleable(),
                TextColumn::make('reports_count')
                    ->label('Reports Count')
                    ->counts('reports')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'pending',
                        'published' => 'published',
                        'rejected' => 'rejected',
                        'hidden' => 'hidden',
                        'under_review' => 'under_review',
                    ]),
                SelectFilter::make('rating')
                    ->label('Rating')
                    ->options([
                        '5' => '5',
                        '4' => '4+',
                        '3' => '3+',
                        '2' => '2+',
                        '1' => '1+',
                    ])
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->where('rating', '>=', (int) $data['value'])
                        : $query),
                SelectFilter::make('category')
                    ->label('Category')
                    ->options(fn () => Category::query()->orderBy('name')->pluck('name', 'id'))
                    ->query(function (Builder $query, array $data): Builder {
                        $id = $data['value'] ?? null;
                        if (! $id) {
                            return $query;
                        }

                        return $query->whereHas('profile.categories', fn (Builder $q) => $q->where('categories.id', $id));
                    }),
                TernaryFilter::make('is_verified_purchase')->label('Is Verified'),
                TernaryFilter::make('is_anonymous')->label('Is Anonymous'),
                TernaryFilter::make('is_suspicious')->label('Is Suspicious'),
                Filter::make('has_reports')
                    ->label('Has Reports')
                    ->query(fn (Builder $query) => $query->whereHas('reports')),
                Filter::make('created_at')
                    ->label('Created At')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('publish')
                    ->label('Publish')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->action(fn ($record) => $record->update(['status' => 'published', 'published_at' => now()])),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->action(fn ($record) => $record->update(['status' => 'rejected'])),
                Action::make('hide')
                    ->label('Hide')
                    ->icon('heroicon-o-eye-slash')
                    ->color('warning')
                    ->action(fn ($record) => $record->update(['status' => 'hidden'])),
                Action::make('mark_verified')
                    ->label('Mark as Verified')
                    ->icon('heroicon-o-check-badge')
                    ->action(fn ($record) => $record->update(['is_verified_purchase' => true])),
                Action::make('mark_suspicious')
                    ->label('Mark as Suspicious')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('warning')
                    ->action(fn ($record) => $record->update(['is_suspicious' => true, 'status' => 'under_review'])),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('publish_selected')
                        ->label('Publish selected')
                        ->icon('heroicon-o-check')
                        ->action(fn (Collection $records) => $records->each->update(['status' => 'published', 'published_at' => now()])),
                    BulkAction::make('reject_selected')
                        ->label('Reject selected')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->action(fn (Collection $records) => $records->each->update(['status' => 'rejected'])),
                    BulkAction::make('hide_selected')
                        ->label('Hide selected')
                        ->icon('heroicon-o-eye-slash')
                        ->color('warning')
                        ->action(fn (Collection $records) => $records->each->update(['status' => 'hidden'])),
                    BulkAction::make('mark_verified_selected')
                        ->label('Mark as verified selected')
                        ->icon('heroicon-o-check-badge')
                        ->action(fn (Collection $records) => $records->each->update(['is_verified_purchase' => true])),
                    BulkAction::make('delete_selected')
                        ->label('Delete selected')
                        ->icon('heroicon-o-trash')
                        ->requiresConfirmation()
                        ->color('danger')
                        ->action(fn (Collection $records) => $records->each->delete()),
                ]),
            ])
            ->striped();
    }
}
