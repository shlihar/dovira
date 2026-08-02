<?php

namespace App\Filament\Resources\Top20ImportBatches;

use App\Filament\Resources\Top20ImportBatches\Pages\EditTop20ImportBatch;
use App\Filament\Resources\Top20ImportBatches\Pages\ListTop20ImportBatches;
use App\Filament\Resources\Top20ImportBatches\RelationManagers\ItemsRelationManager;
use App\Models\Top20ImportBatch;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

class Top20ImportBatchResource extends Resource
{
    protected static ?string $model = Top20ImportBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Top20 імпорти';

    protected static ?string $modelLabel = 'Top20 імпорт';

    protected static ?string $pluralModelLabel = 'Top20 імпорти';

    protected static string|UnitEnum|null $navigationGroup = 'Каталог';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Запуск імпорту')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Placeholder::make('name')
                                    ->label('Назва')
                                    ->content(fn (?Top20ImportBatch $record) => $record?->name ?: 'Без назви'),
                                Placeholder::make('status')
                                    ->label('Статус')
                                    ->content(fn (?Top20ImportBatch $record) => match ($record?->status) {
                                        'queued' => 'У черзі',
                                        'completed' => 'Завершено',
                                        'completed_with_errors' => 'Завершено з помилками',
                                        'processing' => 'В процесі',
                                        'failed' => 'Помилка',
                                        default => $record?->status ?: '—',
                                    }),
                                Placeholder::make('created_at')
                                    ->label('Створено')
                                    ->content(fn (?Top20ImportBatch $record) => $record?->created_at?->format('d.m.Y H:i') ?: '—'),
                                Placeholder::make('category')
                                    ->label('Категорія')
                                    ->content(fn (?Top20ImportBatch $record) => $record?->category?->name ?: '—'),
                                Placeholder::make('subcategory')
                                    ->label('Підкатегорія')
                                    ->content(fn (?Top20ImportBatch $record) => $record?->subcategory?->name ?: '—'),
                                Placeholder::make('city')
                                    ->label('Місто')
                                    ->content(fn (?Top20ImportBatch $record) => $record?->city_name ?: '—'),
                                Placeholder::make('page_range')
                                    ->label('Сторінки')
                                    ->content(fn (?Top20ImportBatch $record) => static::formatPageRange($record)),
                            ]),
                        Placeholder::make('listing_url')
                            ->label('Top20 URL')
                            ->content(fn (?Top20ImportBatch $record) => $record?->listing_url ?: '—'),
                    ]),
                Section::make('Результат')
                    ->schema([
                        Placeholder::make('progress')
                            ->label('Прогрес')
                            ->content(fn (?Top20ImportBatch $record) => new HtmlString(view('filament.resources.top20-import-batches.progress', [
                                'record' => $record,
                            ])->render()))
                            ->columnSpanFull(),
                        Grid::make(5)
                            ->schema([
                                Placeholder::make('profiles_created')
                                    ->label('Нових профілів')
                                    ->content(fn (?Top20ImportBatch $record) => (string) ($record?->profiles_created ?? 0)),
                                Placeholder::make('profiles_updated')
                                    ->label('Оновлених профілів')
                                    ->content(fn (?Top20ImportBatch $record) => (string) ($record?->profiles_updated ?? 0)),
                                Placeholder::make('profiles_skipped')
                                    ->label('Пропущено')
                                    ->content(fn (?Top20ImportBatch $record) => (string) ($record?->profiles_skipped ?? 0)),
                                Placeholder::make('reviews_created')
                                    ->label('Нових відгуків')
                                    ->content(fn (?Top20ImportBatch $record) => (string) ($record?->reviews_created ?? 0)),
                                Placeholder::make('reviews_updated')
                                    ->label('Оновлених відгуків')
                                    ->content(fn (?Top20ImportBatch $record) => (string) ($record?->reviews_updated ?? 0)),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('name')->label('Назва')->searchable()->placeholder('Без назви'),
                TextColumn::make('category.name')->label('Категорія')->placeholder('—'),
                TextColumn::make('subcategory.name')->label('Підкатегорія')->placeholder('—'),
                TextColumn::make('city_name')->label('Місто')->placeholder('—'),
                TextColumn::make('page_range')
                    ->label('Сторінки')
                    ->state(fn (Top20ImportBatch $record) => static::formatPageRange($record)),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'queued' => 'gray',
                        'completed' => 'success',
                        'completed_with_errors' => 'warning',
                        'processing' => 'info',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('profiles_created')->label('Створено')->numeric()->sortable(),
                TextColumn::make('profiles_updated')->label('Оновлено')->numeric()->sortable(),
                TextColumn::make('errors_count')->label('Помилки')->numeric()->sortable(),
                TextColumn::make('created_at')->label('Дата')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'queued' => 'У черзі',
                        'processing' => 'В процесі',
                        'completed' => 'Завершено',
                        'completed_with_errors' => 'З помилками',
                        'failed' => 'Помилка',
                    ]),
            ])
            ->recordActions([
                EditAction::make()->label('Відкрити'),
            ])
            ->recordUrl(fn (Top20ImportBatch $record) => static::getUrl('edit', ['record' => $record]));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['category', 'subcategory']);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTop20ImportBatches::route('/'),
            'edit' => EditTop20ImportBatch::route('/{record}/edit'),
        ];
    }

    private static function formatPageRange(?Top20ImportBatch $record): string
    {
        if (! $record) {
            return '—';
        }

        $startPage = max(1, (int) data_get($record->options, 'start_page', 1));
        $endPage = data_get($record->options, 'end_page');

        if (filled($endPage)) {
            return sprintf('%d-%d', $startPage, max($startPage, (int) $endPage));
        }

        if ($startPage > 1) {
            return $startPage . '+';
        }

        return 'Усі';
    }
}
