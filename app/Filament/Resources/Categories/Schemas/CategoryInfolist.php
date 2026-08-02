<?php

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Http\Request;

class CategoryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Category')
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('name')->label('Name'),
                        TextEntry::make('slug')->label('Slug'),
                        TextEntry::make('parent.name')->label('Parent Category')->placeholder('—'),
                        TextEntry::make('status')->label('Status')->badge(),
                        TextEntry::make('sort_order')->label('Sort Order'),
                        IconEntry::make('show_on_homepage')->label('Show on Homepage')->boolean(),
                        IconEntry::make('show_in_catalog')->label('Show in Catalog')->boolean(),
                        IconEntry::make('is_indexable')->label('Is Indexable')->boolean(),
                        IconEntry::make('pro_enabled')->label('PRO Enabled')->boolean(),
                        TextEntry::make('created_at')->label('Created')->dateTime('Y-m-d H:i'),
                        TextEntry::make('updated_at')->label('Updated')->dateTime('Y-m-d H:i'),
                    ])
                    ->columns(3),

                Section::make('Аналітика категорії')
                    ->schema([
                        Livewire::make(\App\Livewire\Filament\CategoryAnalyticsFilters::class, [
                            'recordId' => static::resolveRecordIdFromRequest(request()),
                        ]),
                    ]),
            ]);
    }

    private static function resolveRecordIdFromRequest(Request $request): int
    {
        $record = $request->route('record');

        if (is_object($record) && method_exists($record, 'getKey')) {
            return (int) $record->getKey();
        }

        return (int) $record;
    }
}
