<?php

namespace App\Filament\Resources\ReviewReports;

use App\Filament\Resources\ReviewReports\Pages\ListReviewReports;
use App\Filament\Resources\ReviewReports\Schemas\ReviewReportInfolist;
use App\Filament\Resources\ReviewReports\Tables\ReviewReportsTable;
use App\Models\ReviewReport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ReviewReportResource extends Resource
{
    protected static ?string $model = ReviewReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?string $navigationLabel = 'Скарги на відгуки';

    protected static ?string $modelLabel = 'Скарга на відгук';

    protected static ?string $pluralModelLabel = 'Скарги на відгуки';

    protected static string|UnitEnum|null $navigationGroup = 'Trust / Moderation';

    protected static ?int $navigationSort = 0;

    /** Лічильник відкритих скарг у меню — щоб модерація одразу бачила чергу. */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::query()->whereIn('status', ['open', 'in_review'])->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function infolist(Schema $schema): Schema
    {
        return ReviewReportInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReviewReportsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['review.profile', 'reporter']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviewReports::route('/'),
        ];
    }
}
