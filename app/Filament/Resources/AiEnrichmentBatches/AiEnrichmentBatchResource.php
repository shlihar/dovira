<?php

namespace App\Filament\Resources\AiEnrichmentBatches;

use App\Filament\Resources\AiEnrichmentBatches\Pages\CreateAiEnrichmentBatch;
use App\Filament\Resources\AiEnrichmentBatches\Pages\EditAiEnrichmentBatch;
use App\Filament\Resources\AiEnrichmentBatches\Pages\ListAiEnrichmentBatches;
use App\Filament\Resources\AiEnrichmentBatches\RelationManagers\TasksRelationManager;
use App\Jobs\ProcessAiEnrichmentBatch;
use App\Models\AiEnrichmentBatch;
use App\Models\Category;
use App\Services\Queue\AiEnrichmentQueueWorkerManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AiEnrichmentBatchResource extends Resource
{
    protected static ?string $model = AiEnrichmentBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'AI-збагачення';

    protected static ?string $modelLabel = 'AI-збагачення';

    protected static ?string $pluralModelLabel = 'AI-збагачення';

    protected static string|UnitEnum|null $navigationGroup = 'Каталог';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make('ai_enrichment_tabs')
                    ->tabs([
                        Tab::make('Стартові дані')
                            ->schema([
                                Section::make('Новий запуск')
                                    ->description('Профілі створюються як чернетки або задачі на перевірку. Автопублікації немає.')
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('Назва запуску')
                                            ->placeholder('Наприклад: Стоматологи Київ, травень')
                                            ->maxLength(255),
                                        Select::make('source_type')
                                            ->label('Спосіб старту')
                                            ->options([
                                                'manual' => 'Вставити список вручну',
                                                'csv' => 'CSV / TXT файл',
                                                'existing_profiles' => 'Існуючі неповні профілі',
                                                'google_places' => 'Google Places API (пізніше)',
                                                'uadvokat' => 'Імпорт адвокатів з uadvokat.com.ua',
                                                'selected_profiles' => 'Вибрані профілі (bulk-дія)',
                                            ])
                                            ->default('manual')
                                            ->required()
                                            ->live(),
                                        Select::make('default_category_id')
                                            ->label('Категорія за замовчуванням')
                                            ->options(fn () => Category::query()
                                                ->whereNull('parent_id')
                                                ->orderBy('sort_order')
                                                ->orderBy('name')
                                                ->pluck('name', 'id'))
                                            ->searchable()
                                            ->preload(),
                                        TextInput::make('default_city')
                                            ->label('Місто за замовчуванням')
                                            ->maxLength(255),
                                        TextInput::make('default_country')
                                            ->label('Країна')
                                            ->default('Україна')
                                            ->maxLength(255),
                                        Select::make('language')
                                            ->label('Мова')
                                            ->options([
                                                'uk' => 'Українська',
                                                'en' => 'English',
                                            ])
                                            ->default('uk')
                                            ->required(),
                                        Textarea::make('input_text')
                                            ->label('Список профілів')
                                            ->placeholder("Smile Dental Київ\nАдвокат Іван Петренко Львів\nCryptoBox Київ")
                                            ->helperText('Один профіль = один рядок. Лапки не потрібні. Після вставки перевірте, що кожен профіль на новому рядку.')
                                            ->rows(10)
                                            ->columnSpanFull()
                                            ->visible(fn ($get) => $get('source_type') === 'manual'),
                                        FileUpload::make('file_path')
                                            ->label('CSV / TXT файл')
                                            ->disk('local')
                                            ->directory('ai-enrichment-imports')
                                            ->acceptedFileTypes([
                                                'text/csv',
                                                'text/plain',
                                                'application/vnd.ms-excel',
                                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                            ])
                                            ->helperText('MVP стабільно обробляє CSV/TXT. Excel-файл можна зберегти, парсер підключимо окремо.')
                                            ->visible(fn ($get) => $get('source_type') === 'csv'),
                                    ])
                                    ->columns(3),
                            ]),
                        Tab::make('Опції AI')
                            ->schema([
                                Section::make('Що робити з профілями')
                                    ->schema([
                                        Select::make('options.speed_mode')
                                            ->label('Режим швидкості')
                                            ->options([
                                                'fast' => 'Швидкий: мінімум додаткових пошуків',
                                                'standard' => 'Стандартний: баланс швидкості й якості',
                                                'deep' => 'Глибокий: максимум джерел, повільніше',
                                            ])
                                            ->default('standard')
                                            ->helperText('Для великих списків використовуйте "Швидкий" або "Стандартний". "Глибокий" робить додаткові повторні пошуки.'),
                                        Grid::make(3)
                                            ->schema([
                                                Toggle::make('options.find_website')
                                                    ->label('Шукати сайт')
                                                    ->default(true),
                                                Toggle::make('options.find_socials')
                                                    ->label('Шукати соцмережі')
                                                    ->default(true),
                                                Toggle::make('options.find_reviews')
                                                    ->label('Шукати зовнішні згадки')
                                                    ->default(true),
                                                Toggle::make('options.generate_description')
                                                    ->label('Генерувати опис')
                                                    ->default(true),
                                                Toggle::make('options.generate_seo')
                                                    ->label('Генерувати SEO')
                                                    ->default(true),
                                                Toggle::make('options.check_duplicates')
                                                    ->label('Перевіряти дублікати')
                                                    ->default(true),
                                            ]),
                                    ]),
                            ]),
                        Tab::make('Результати')
                            ->schema([
                                View::make('filament.resources.ai-enrichment-batches.tabs.results')
                                    ->viewData(fn (?AiEnrichmentBatch $record) => ['record' => $record])
                                    ->columnSpanFull(),
                            ])
                            ->visible(fn (?AiEnrichmentBatch $record) => filled($record?->id)),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('name')->label('Назва')->searchable()->placeholder('Без назви'),
                TextColumn::make('source_type')->label('Джерело')->badge(),
                TextColumn::make('defaultCategory.name')->label('Категорія')->placeholder('Авто'),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'completed' => 'success',
                        'completed_with_errors' => 'warning',
                        'processing' => 'info',
                        'queued' => 'info',
                        'cancelled' => 'gray',
                        'failed' => 'danger',
                        'archived' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('total_items')->label('Рядків')->numeric()->sortable(),
                TextColumn::make('drafts_created')->label('Чернеток')->numeric()->sortable(),
                TextColumn::make('duplicates_found')->label('Дублікатів')->numeric()->sortable(),
                TextColumn::make('errors_count')->label('Помилок')->numeric()->sortable(),
                TextColumn::make('needs_review_count')->label('На перевірці')->numeric()->sortable(),
                TextColumn::make('created_at')->label('Створено')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'draft' => 'Чернетка',
                        'queued' => 'У черзі',
                        'processing' => 'В процесі',
                        'cancelled' => 'Зупинено',
                        'completed' => 'Завершено',
                        'completed_with_errors' => 'З помилками',
                        'archived' => 'Архів',
                    ]),
            ])
            ->recordActions([
                Action::make('process')
                    ->label('Запустити')
                    ->icon('heroicon-o-play')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->visible(fn (AiEnrichmentBatch $record) => ! in_array($record->status, ['queued', 'processing'], true))
                    ->action(function (AiEnrichmentBatch $record): void {
                        $record->update(['status' => 'queued']);

                        ProcessAiEnrichmentBatch::dispatchAndEnsureWorker($record->id);
                        $workers = count(app(AiEnrichmentQueueWorkerManager::class)->runningWorkerPids());

                        Notification::make()
                            ->title('AI-збагачення поставлено в обробку')
                            ->body($workers > 0
                                ? "Активних worker: {$workers}."
                                : 'Worker не запущений. Для локальної розробки запустіть composer dev або php artisan dovira:ai-enrichment:work.')
                            ->success()
                            ->send();
                    }),
                Action::make('cancel')
                    ->label('Зупинити')
                    ->icon('heroicon-o-stop')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (AiEnrichmentBatch $record) => in_array($record->status, ['queued', 'processing'], true))
                    ->action(function (AiEnrichmentBatch $record): void {
                        app(AiEnrichmentQueueWorkerManager::class)->stopAllWorkers(true);

                        $record->tasks()
                            ->whereIn('status', ['pending', 'processing'])
                            ->update([
                                'status' => 'rejected',
                                'error_message' => 'Зупинено адміністратором.',
                                'processed_at' => now(),
                            ]);

                        $record->update([
                            'status' => 'cancelled',
                            'finished_at' => now(),
                        ]);

                        Notification::make()
                            ->title('AI-збагачення зупинено')
                            ->warning()
                            ->send();
                    }),
                Action::make('kick_queue')
                    ->label('Продовжити')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->visible(fn (AiEnrichmentBatch $record) => in_array($record->status, ['queued', 'processing'], true))
                    ->action(function (): void {
                        $manager = app(AiEnrichmentQueueWorkerManager::class);
                        $result = $manager->restartWorkerAndRecoverQueue();
                        $manager->startManagedWorker();
                        $workers = count($manager->runningWorkerPids());

                        Notification::make()
                            ->title('Worker відновлено')
                            ->body("Зупинено: {$result['stopped_workers']}. Повернуто в чергу: {$result['released_jobs']}. Активних worker: {$workers}.")
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
                Action::make('archive')
                    ->label('Архівувати')
                    ->icon('heroicon-o-archive-box')
                    ->color('danger')
                    ->visible(fn (AiEnrichmentBatch $record) => $record->status !== 'archived')
                    ->requiresConfirmation()
                    ->action(fn (AiEnrichmentBatch $record) => $record->update(['status' => 'archived'])),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $statusFilter = data_get(request()->input('tableFilters', []), 'status.value');
        $showArchived = $statusFilter === 'archived';

        if (! $showArchived) {
            $query->where(fn (Builder $q) => $q
                ->whereNull('status')
                ->orWhere('status', '!=', 'archived'));
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [
            TasksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiEnrichmentBatches::route('/'),
            'create' => CreateAiEnrichmentBatch::route('/create'),
            'edit' => EditAiEnrichmentBatch::route('/{record}/edit'),
        ];
    }
}
