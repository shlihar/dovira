<?php

namespace App\Filament\Resources\Categories\Widgets;

use App\Models\Category;
use App\Models\Profile;
use App\Models\ProfileReview;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class CategoryDirectionsTableWidget extends TableWidget
{
    protected int | string | array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public ?Category $record = null;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Напрями / послуги в категорії')
            ->query($this->getBaseQuery())
            ->defaultSort('profiles_count_runtime', 'desc')
            ->defaultPaginationPageOption(10)
            ->columns([
                TextColumn::make('name')
                    ->label('Напрям')
                    ->searchable()
                    ->weight('semibold'),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->copyable(),
                TextColumn::make('profiles_count_runtime')
                    ->label('Профілі')
                    ->state(fn ($record) => $this->profilesCount((int) $record->id))
                    ->sortable(),
                TextColumn::make('reviews_count_runtime')
                    ->label('Відгуки')
                    ->state(fn ($record) => $this->reviewsCount((int) $record->id))
                    ->sortable(),
                TextColumn::make('views_count_runtime')
                    ->label('Перегляди')
                    ->state(fn ($record) => $this->viewsCount((int) $record->id))
                    ->sortable(),
                TextColumn::make('pro_profiles_count_runtime')
                    ->label('PRO')
                    ->state(fn ($record) => $this->proProfilesCount((int) $record->id))
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Активний')
                    ->boolean(),
            ]);
    }

    private function getBaseQuery(): Builder
    {
        if (! $this->record) {
            return \App\Models\CategoryService::query()->whereRaw('1=0');
        }

        return $this->record->services()->getQuery();
    }

    private function profilesCount(int $serviceId): int
    {
        return Profile::query()
            ->whereHas('services', fn (Builder $query) => $query->where('category_services.id', $serviceId))
            ->count();
    }

    private function reviewsCount(int $serviceId): int
    {
        return ProfileReview::query()
            ->where('status', 'published')
            ->whereHas('profile.services', fn (Builder $query) => $query->where('category_services.id', $serviceId))
            ->count();
    }

    private function viewsCount(int $serviceId): int
    {
        return (int) Profile::query()
            ->whereHas('services', fn (Builder $query) => $query->where('category_services.id', $serviceId))
            ->sum('views_count');
    }

    private function proProfilesCount(int $serviceId): int
    {
        return Profile::query()
            ->where('is_pro', true)
            ->whereHas('services', fn (Builder $query) => $query->where('category_services.id', $serviceId))
            ->count();
    }
}

