<?php

namespace App\Filament\Resources\Profiles;

use App\Filament\Resources\Profiles\Pages\CreateProfile;
use App\Filament\Resources\Profiles\Pages\EditProfile;
use App\Filament\Resources\Profiles\Pages\ListProfiles;
use App\Filament\Resources\Profiles\RelationManagers\ClaimsRelationManager;
use App\Filament\Resources\Profiles\RelationManagers\ProSubscriptionsRelationManager;
use App\Filament\Resources\Profiles\RelationManagers\ReviewReportsRelationManager;
use App\Filament\Resources\Profiles\RelationManagers\ReviewsRelationManager;
use App\Filament\Resources\Profiles\RelationManagers\StatusHistoryRelationManager;
use App\Filament\Resources\Profiles\Schemas\ProfileForm;
use App\Filament\Resources\Profiles\Tables\ProfilesTable;
use App\Models\Profile;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProfileResource extends Resource
{
    protected static ?string $model = Profile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;
    protected static ?string $navigationLabel = 'Профілі';
    protected static ?string $modelLabel = 'Профіль';
    protected static ?string $pluralModelLabel = 'Профілі';
    protected static string|UnitEnum|null $navigationGroup = 'Каталог';
    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return ProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProfilesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->select('profiles.*')
            ->with([
                'region:id,name',
                'services:id,name',
                'primaryCategory:id,name,parent_id',
                'primaryCategory.parent:id,name',
            ])
            ->withExists([
                'claims as has_approved_claim' => fn ($claims) => $claims->where('status', 'approved'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ReviewsRelationManager::class,
            ReviewReportsRelationManager::class,
            ClaimsRelationManager::class,
            ProSubscriptionsRelationManager::class,
            StatusHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProfiles::route('/'),
            'create' => CreateProfile::route('/create'),
            'edit' => EditProfile::route('/{record}'),
        ];
    }
}
