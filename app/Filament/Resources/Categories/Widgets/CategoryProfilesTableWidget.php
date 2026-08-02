<?php

namespace App\Filament\Resources\Categories\Widgets;

use App\Models\Category;
use App\Models\Profile;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class CategoryProfilesTableWidget extends TableWidget
{
    protected int | string | array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public ?Category $record = null;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Профілі в категорії')
            ->query($this->getBaseQuery())
            ->defaultPaginationPageOption(10)
            ->defaultSort('views_count', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Профіль')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),
                TextColumn::make('type')
                    ->label('Тип')
                    ->badge(),
                TextColumn::make('views_count')
                    ->label('Перегляди')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('reviews_count')
                    ->label('Відгуки')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('rating_avg')
                    ->label('Рейтинг')
                    ->numeric(decimalPlaces: 1)
                    ->sortable(),
                IconColumn::make('is_pro')
                    ->label('PRO')
                    ->boolean(),
                TextColumn::make('popularity_score')
                    ->label('Популярність')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('listing_mode')
                    ->label('Показати')
                    ->options([
                        'all' => 'Всі профілі категорії',
                        'popular' => 'Найпопулярніші (за переглядами)',
                        'best_reviews' => 'Найкращі (за відгуками)',
                    ])
                    ->default('all')
                    ->query(function (Builder $query, array $data): Builder {
                        $mode = $data['value'] ?? 'all';

                        return match ($mode) {
                            'popular' => $query->orderByDesc('views_count')->orderByDesc('popularity_score'),
                            'best_reviews' => $query->orderByDesc('reviews_count')->orderByDesc('rating_avg'),
                            default => $query->orderByDesc('created_at'),
                        };
                    }),
            ])
            ->recordUrl(fn (Profile $record) => route('filament.admin.resources.profiles.edit', ['record' => $record]));
    }

    private function getBaseQuery(): Builder
    {
        if (! $this->record) {
            return Profile::query()->whereRaw('1=0');
        }

        $categoryIds = [$this->record->id, ...$this->record->children()->pluck('id')->all()];

        return Profile::query()
            ->whereHas('categories', fn (Builder $query) => $query->whereIn('categories.id', $categoryIds))
            ->select('profiles.*');
    }
}
