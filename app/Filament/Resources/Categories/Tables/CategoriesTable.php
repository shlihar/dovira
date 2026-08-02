<?php

namespace App\Filament\Resources\Categories\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->copyable()
                    ->limit(32),
                TextColumn::make('parent.name')
                    ->label('Parent Category')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('category_type')
                    ->label('Тип')
                    ->badge()
                    ->state(fn ($record) => $record->parent_id ? 'Підкатегорія' : 'Категорія')
                    ->color(fn ($record) => $record->parent_id ? 'info' : 'success'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'inactive' => 'gray',
                        'draft' => 'warning',
                        'hidden' => 'danger',
                        'archived' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('profiles_count')->label('Profiles Count')->numeric()->sortable(),
                TextColumn::make('reviews_count')->label('Reviews Count')->numeric()->sortable(),
                TextColumn::make('pro_profiles_count')->label('PRO Profiles Count')->numeric()->sortable(),
                IconColumn::make('show_on_homepage')->label('Show on Homepage')->boolean(),
                IconColumn::make('show_in_catalog')->label('Show in Catalog')->boolean(),
                TextColumn::make('sort_order')->label('Sort Order')->numeric()->sortable(),
                TextColumn::make('created_at')->label('Created At')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'active',
                        'inactive' => 'inactive',
                        'draft' => 'draft',
                        'hidden' => 'hidden',
                        'archived' => 'archived',
                    ]),
                SelectFilter::make('parent_id')
                    ->label('Parent Category')
                    ->relationship('parent', 'name'),
                SelectFilter::make('level')
                    ->label('Тип')
                    ->options([
                        'root' => 'Категорія',
                        'child' => 'Підкатегорія',
                    ])
                    ->query(function ($query, array $data) {
                        return match ($data['value'] ?? null) {
                            'root' => $query->whereNull('parent_id'),
                            'child' => $query->whereNotNull('parent_id'),
                            default => $query,
                        };
                    }),
                TernaryFilter::make('show_on_homepage')->label('Show on Homepage'),
                TernaryFilter::make('show_in_catalog')->label('Show in Catalog'),
                TernaryFilter::make('is_indexable')->label('Is Indexable'),
                TernaryFilter::make('pro_enabled')->label('PRO Enabled'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->action(function ($record): void {
                        $copy = $record->replicate();
                        $copy->name = $record->name . ' (copy)';
                        $copy->slug = Str::slug($record->slug . '-copy-' . now()->format('His'));
                        $copy->save();

                        Notification::make()
                            ->title('Category duplicated')
                            ->success()
                            ->send();
                    }),
                Action::make('activate')
                    ->label('Activate')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn ($record) => $record->status !== 'active')
                    ->action(fn ($record) => $record->update(['status' => 'active', 'is_active' => true])),
                Action::make('deactivate')
                    ->label('Deactivate')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->visible(fn ($record) => $record->status === 'active')
                    ->action(fn ($record) => $record->update(['status' => 'inactive', 'is_active' => false])),
                Action::make('open_on_website')
                    ->label('Open on Website')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record) => route('catalog', ['categories' => [$record->name]]))
                    ->openUrlInNewTab(),
                Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status !== 'archived')
                    ->action(fn ($record) => $record->update(['status' => 'archived', 'is_active' => false])),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('activate_selected')
                        ->label('Activate selected')
                        ->icon('heroicon-o-check-circle')
                        ->action(fn (Collection $records) => $records->each->update(['status' => 'active', 'is_active' => true])),
                    BulkAction::make('deactivate_selected')
                        ->label('Deactivate selected')
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->action(fn (Collection $records) => $records->each->update(['status' => 'inactive', 'is_active' => false])),
                    BulkAction::make('show_homepage_selected')
                        ->label('Show on homepage')
                        ->icon('heroicon-o-home')
                        ->action(fn (Collection $records) => $records->each->update(['show_on_homepage' => true])),
                    BulkAction::make('hide_homepage_selected')
                        ->label('Hide from homepage')
                        ->icon('heroicon-o-eye-slash')
                        ->action(fn (Collection $records) => $records->each->update(['show_on_homepage' => false])),
                    BulkAction::make('archive_selected')
                        ->label('Archive selected')
                        ->icon('heroicon-o-archive-box')
                        ->color('danger')
                        ->action(fn (Collection $records) => $records->each->update(['status' => 'archived', 'is_active' => false])),
                    BulkAction::make('delete_selected')
                        ->label('Delete selected')
                        ->icon('heroicon-o-trash')
                        ->requiresConfirmation()
                        ->color('danger')
                        ->action(fn (Collection $records) => $records->each->delete()),
                ]),
            ]);
    }
}
