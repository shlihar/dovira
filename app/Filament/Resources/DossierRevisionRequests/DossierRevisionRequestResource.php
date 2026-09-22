<?php

namespace App\Filament\Resources\DossierRevisionRequests;

use App\Filament\Resources\DossierRevisionRequests\Pages\ListDossierRevisionRequests;
use App\Filament\Resources\DossierRevisionRequests\Tables\DossierRevisionRequestsTable;
use App\Models\DossierRevisionRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Запити PRO-власників на перегляд досьє: що неточне / що змінити.
 * Команда перевіряє факти й переробляє досьє вручну.
 */
class DossierRevisionRequestResource extends Resource
{
    protected static ?string $model = DossierRevisionRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static ?string $navigationLabel = 'Перегляд досьє';

    protected static ?string $modelLabel = 'Запит на перегляд досьє';

    protected static ?string $pluralModelLabel = 'Запити на перегляд досьє';

    protected static string|UnitEnum|null $navigationGroup = 'Trust / Moderation';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return DossierRevisionRequestsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['profile', 'user']);
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = DossierRevisionRequest::query()
            ->whereIn('status', DossierRevisionRequest::OPEN_STATUSES)
            ->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDossierRevisionRequests::route('/'),
        ];
    }
}
