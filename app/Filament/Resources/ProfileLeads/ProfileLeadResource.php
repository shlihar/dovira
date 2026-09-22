<?php

namespace App\Filament\Resources\ProfileLeads;

use App\Filament\Resources\ProfileLeads\Pages\ListProfileLeads;
use App\Filament\Resources\ProfileLeads\Pages\ViewProfileLead;
use App\Filament\Resources\ProfileLeads\Schemas\ProfileLeadInfolist;
use App\Filament\Resources\ProfileLeads\Tables\ProfileLeadsTable;
use App\Models\ProfileLead;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ProfileLeadResource extends Resource
{
    protected static ?string $model = ProfileLead::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?string $navigationLabel = 'Заявки клієнтів';

    protected static ?string $modelLabel = 'Заявка клієнта';

    protected static ?string $pluralModelLabel = 'Заявки клієнтів';

    protected static string|UnitEnum|null $navigationGroup = 'Trust / Moderation';

    protected static ?int $navigationSort = 2;

    public static function infolist(Schema $schema): Schema
    {
        return ProfileLeadInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProfileLeadsTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        $unread = static::getModel()::query()->where('is_read', false)->count();

        return $unread > 0 ? (string) $unread : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['profile.owner', 'profile.categories']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProfileLeads::route('/'),
            'view' => ViewProfileLead::route('/{record}'),
        ];
    }
}
