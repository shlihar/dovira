<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\ClaimsRelationManager;
use App\Filament\Resources\Users\RelationManagers\OwnedProfilesRelationManager;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Користувачі';

    protected static ?string $modelLabel = 'Користувач';

    protected static ?string $pluralModelLabel = 'Користувачі';

    protected static string|UnitEnum|null $navigationGroup = 'Trust / Moderation';

    protected static ?int $navigationSort = 0;

    protected static ?string $recordTitleAttribute = 'email';

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount(['ownedProfiles', 'profileClaims']);
    }

    public static function getRelations(): array
    {
        return [
            OwnedProfilesRelationManager::class,
            ClaimsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) User::query()->count();
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasAdminPermission('users.view') ?? false;
    }

    public static function canView($record): bool
    {
        return Auth::user()?->hasAdminPermission('users.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasAdminPermission('users.update') ?? false;
    }

    public static function canEdit($record): bool
    {
        return Auth::user()?->hasAdminPermission('users.update') ?? false;
    }

    public static function canDelete($record): bool
    {
        // Видаляти користувачів дозволено лише повному адміну (users.delete
        // немає в конфізі ролей, тож проходить тільки роль з '*').
        return Auth::user()?->hasAdminPermission('users.delete') ?? false;
    }
}
