<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Models\Category;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make('category_tabs')
                    ->persistTabInQueryString('category_tab')
                    ->tabs([
                        Tab::make('Main')
                            ->schema([
                                Section::make('Основна інформація')
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('Назва')
                                            ->required()
                                            ->maxLength(255)
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Str::slug((string) $state))),
                                        TextInput::make('slug')
                                            ->label('Slug')
                                            ->required()
                                            ->maxLength(255)
                                            ->unique(ignoreRecord: true)
                                            ->rules([
                                                fn ($record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                                                    if (! $record || ! filled($value)) {
                                                        return;
                                                    }

                                                    if ((string) $record->slug === (string) $value) {
                                                        return;
                                                    }

                                                    if (Category::query()->where('slug', $value)->exists()) {
                                                        $fail('Цей slug вже використовується.');
                                                    }
                                                },
                                            ]),
                                        Select::make('parent_id')
                                            ->label('Батьківська категорія')
                                            ->options(fn (?Category $record) => Category::query()
                                                ->whereNull('parent_id')
                                                ->when($record, fn ($query) => $query->whereKeyNot($record->id))
                                                ->orderBy('name')
                                                ->pluck('name', 'id'))
                                            ->searchable()
                                            ->preload()
                                            ->nullable()
                                            ->helperText('Залиш порожнім для основної категорії. Вибери батьківську категорію, щоб створити підкатегорію.')
                                            ->rules([
                                                fn ($record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                                                    if (! $record || ! filled($value)) {
                                                        return;
                                                    }

                                                    if ((int) $value === (int) $record->id) {
                                                        $fail('Батьківська категорія не може збігатися з поточною.');
                                                    }
                                                },
                                            ]),
                                        Select::make('status')
                                            ->label('Статус')
                                            ->required()
                                            ->default('active')
                                            ->options([
                                                'active' => 'active',
                                                'inactive' => 'inactive',
                                                'draft' => 'draft',
                                                'hidden' => 'hidden',
                                                'archived' => 'archived',
                                            ]),
                                        TextInput::make('sort_order')
                                            ->label('Порядок сортування')
                                            ->numeric()
                                            ->default(0)
                                            ->required(),
                                        TextInput::make('icon')
                                            ->label('Іконка (клас або URL)')
                                            ->maxLength(255),
                                    ])
                                    ->columns(2),
                            ]),

                        Tab::make('SEO')
                            ->schema([
                                Section::make('SEO')
                                    ->schema([
                                        TextInput::make('seo_title')
                                            ->label('SEO title')
                                            ->maxLength(60),
                                        Textarea::make('seo_description')
                                            ->label('SEO description')
                                            ->maxLength(160)
                                            ->rows(3),
                                        TextInput::make('seo_h1')
                                            ->label('SEO H1')
                                            ->maxLength(255),
                                        Textarea::make('seo_text')
                                            ->label('SEO text')
                                            ->rows(6)
                                            ->columnSpanFull(),
                                        TextInput::make('canonical_url')
                                            ->label('Canonical URL')
                                            ->url()
                                            ->maxLength(255),
                                        TextInput::make('og_title')
                                            ->label('OG title')
                                            ->maxLength(255),
                                        Textarea::make('og_description')
                                            ->label('OG description')
                                            ->rows(3)
                                            ->maxLength(160),
                                        FileUpload::make('og_image')
                                            ->label('OG image')
                                            ->image()
                                            ->disk('public')
                                            ->visibility('public')
                                            ->directory('categories/og'),
                                        Toggle::make('is_indexable')
                                            ->label('Індексувати сторінку')
                                            ->default(true),
                                    ])
                                    ->columns(2),
                            ]),

                        Tab::make('Display')
                            ->schema([
                                Section::make('Налаштування відображення')
                                    ->schema([
                                        Toggle::make('show_on_homepage')->label('Показувати на головній')->default(false),
                                        Toggle::make('show_in_menu')->label('Показувати в меню')->default(true),
                                        Toggle::make('show_in_footer')->label('Показувати у футері')->default(false),
                                        Toggle::make('show_in_catalog')->label('Показувати в каталозі')->default(true),
                                    ])
                                    ->columns(2),
                            ]),

                        Tab::make('Filters')
                            ->schema([
                                Section::make('Фільтри категорії')
                                    ->schema([
                                        Placeholder::make('filters_hint')
                                            ->label('')
                                            ->content('Таблиця фільтрів доступна на сторінці перегляду категорії у вкладці "Фільтри".'),
                                    ]),
                            ]),

                        Tab::make('PRO')
                            ->schema([
                                Section::make('PRO налаштування')
                                    ->schema([
                                        Toggle::make('pro_enabled')
                                            ->label('PRO доступний для категорії')
                                            ->default(true),
                                        TextInput::make('pro_price')
                                            ->label('PRO price')
                                            ->numeric()
                                            ->minValue(0)
                                            ->prefix('$'),
                                    ])
                                    ->columns(2),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
