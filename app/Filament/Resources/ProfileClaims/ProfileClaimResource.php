<?php

namespace App\Filament\Resources\ProfileClaims;

use App\Filament\Resources\ProfileClaims\Pages\ListProfileClaims;
use App\Filament\Resources\ProfileClaims\Pages\ViewProfileClaim;
use App\Filament\Resources\ProfileClaims\Schemas\ProfileClaimInfolist;
use App\Filament\Resources\ProfileClaims\Tables\ProfileClaimsTable;
use App\Models\ProfileClaim;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ProfileClaimResource extends Resource
{
    protected static ?string $model = ProfileClaim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $navigationLabel = 'Заявки на привʼязку';

    protected static ?string $modelLabel = 'Заявка на привʼязку';

    protected static ?string $pluralModelLabel = 'Заявки на привʼязку';

    protected static string|UnitEnum|null $navigationGroup = 'Trust / Moderation';

    protected static ?int $navigationSort = 1;

    public static function infolist(Schema $schema): Schema
    {
        return ProfileClaimInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProfileClaimsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['profile.owner', 'user', 'reviewer']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProfileClaims::route('/'),
            'view' => ViewProfileClaim::route('/{record}'),
        ];
    }
}
