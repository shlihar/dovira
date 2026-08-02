<?php

namespace App\Filament\Resources\Profiles\Pages;

use App\Filament\Resources\Profiles\ProfileResource;
use App\Filament\Resources\Top20ImportBatches\Top20ImportBatchResource;
use App\Jobs\RunTop20BulkImport;
use App\Models\Category;
use App\Models\CategoryService;
use App\Models\Profile;
use App\Models\Region;
use App\Models\Top20ImportBatch;
use App\Services\Top20BulkImportService;
use App\Services\Queue\AiEnrichmentQueueWorkerManager;
use App\Support\CategoryHierarchy;
use App\Support\Top20ImportUrl;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListProfiles extends ListRecords
{
    protected static string $resource = ProfileResource::class;

    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()
            ->where(function (Builder $query): void {
                $query
                    ->whereDoesntHave('top20ImportItems')
                    ->orWhere(function (Builder $visibleImported): void {
                        $visibleImported
                            ->where('status', '!=', 'draft')
                            ->orWhereNull('status')
                            ->orWhere('is_published', true)
                            ->orWhere('show_in_catalog', true);
                    });
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('exportFiltered')
                ->label('Експорт за фільтрами')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->tooltip('Експортує профілі за поточними фільтрами, пошуком та сортуванням')
                ->action(fn (): StreamedResponse => $this->exportFilteredProfiles()),
            Action::make('exportDossierBatch')
                ->label('Досьє: вигрузити пакет')
                ->icon('heroicon-o-identification')
                ->color('warning')
                ->tooltip('Вигружає JSONL-пакет профілів для генерації досьє. Вигружені позначаються і в наступні пакети не потрапляють.')
                ->form([
                    TextInput::make('count')
                        ->label('Скільки профілів вигрузити')
                        ->helperText('Скільки вкажеш — стільки піде в файл (макс. 1000). Вигружені в наступні пакети не потрапляють.')
                        ->numeric()
                        ->default(50)
                        ->minValue(1)
                        ->maxValue(1000),
                    Select::make('category_id')
                        ->label('Категорія')
                        ->options(fn () => Category::query()->orderBy('name')->pluck('name', 'id'))
                        ->default(90)
                        ->searchable(),
                ])
                ->action(fn (array $data) => $this->exportDossierBatch((int) ($data['count'] ?? 50), (int) ($data['category_id'] ?? 90))),
            Action::make('importCsv')
                ->label('Імпорт CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->form([
                    FileUpload::make('csv_file')
                        ->label('CSV файл')
                        ->acceptedFileTypes([
                            'text/csv',
                            'text/plain',
                            'application/vnd.ms-excel',
                            'text/comma-separated-values',
                        ])
                        ->disk('local')
                        ->directory('imports/profiles')
                        ->required(),
                    Select::make('mode')
                        ->label('Режим імпорту')
                        ->options([
                            'upsert' => 'Оновити існуючі + створити нові',
                            'create' => 'Тільки створити нові',
                            'update' => 'Тільки оновити існуючі',
                        ])
                        ->default('upsert')
                        ->required(),
                    Select::make('delimiter')
                        ->label('Розділювач')
                        ->options([
                            ',' => 'Кома (,)',
                            ';' => 'Крапка з комою (;)',
                            "\t" => 'Табуляція',
                        ])
                        ->default(',')
                        ->required(),
                    Toggle::make('has_header')
                        ->label('Перший рядок містить заголовки')
                        ->default(true),
                ])
                ->action(function (array $data): void {
                    $path = is_array($data['csv_file']) ? Arr::first($data['csv_file']) : $data['csv_file'];
                    $this->importProfilesFromCsv(
                        (string) $path,
                        (string) $data['mode'],
                        (string) $data['delimiter'],
                        (bool) $data['has_header'],
                    );
                }),
            Action::make('importTop20')
                ->label('Імпорт з Top20')
                ->icon('heroicon-o-globe-alt')
                ->color('warning')
                ->form([
                    TextInput::make('listing_url')
                        ->label('Top20 listing URL')
                        ->placeholder('https://top20.ua/od/tag/advokat/')
                        ->helperText('Встав будь-яке посилання Top20 на каталог або тег. Місто буде визначене автоматично з URL.')
                        ->required()
                        ->url(),
                    Select::make('category_slug')
                        ->label('Категорія DOVIRA')
                        ->options(fn () => Category::query()
                            ->whereNull('parent_id')
                            ->orderBy('sort_order')
                            ->orderBy('name')
                            ->pluck('name', 'slug'))
                        ->live()
                        ->searchable()
                        ->required(),
                    Select::make('subcategory_slug')
                        ->label('Підкатегорія DOVIRA')
                        ->options(function (callable $get): array {
                            $categorySlug = (string) ($get('category_slug') ?? '');
                            if ($categorySlug === '') {
                                return [];
                            }

                            $parentId = Category::query()->where('slug', $categorySlug)->value('id');
                            if (! $parentId) {
                                return [];
                            }

                            return Category::query()
                                ->where('parent_id', $parentId)
                                ->orderBy('sort_order')
                                ->orderBy('name')
                                ->pluck('name', 'slug')
                                ->all();
                        })
                        ->searchable()
                        ->placeholder('Без підкатегорії'),
                    TextInput::make('start_page')
                        ->label('З якої сторінки')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->helperText('1 = почати з першої сторінки каталогу.'),
                    TextInput::make('end_page')
                        ->label('По яку сторінку')
                        ->numeric()
                        ->minValue(1)
                        ->placeholder('До кінця каталогу')
                        ->helperText('Наприклад: 5 або 9. Якщо лишити порожнім, імпорт піде далі по всіх сторінках каталогу в межах технічного ліміту.'),
                    TextInput::make('max_profiles')
                        ->label('Макс. профілів')
                        ->numeric()
                        ->minValue(1)
                        ->placeholder('Без обмеження'),
                    TextInput::make('min_reviews')
                        ->label('Мін. кількість відгуків')
                        ->numeric()
                        ->helperText('0 = імпортувати також профілі без відгуків.')
                        ->default(1)
                        ->minValue(0)
                        ->required(),
                    Toggle::make('refresh_existing')
                        ->label('Оновлювати існуючі поля профілю')
                        ->default(false),
                    Toggle::make('publish_profiles')
                        ->label('Одразу публікувати нові профілі')
                        ->default(false)
                        ->helperText('Якщо вимкнено, нові профілі будуть створені як чернетки і залишаться видимими лише в адмінці.'),
                ])
                ->modalWidth('3xl')
                ->action(function (array $data): void {
                    $listingUrl = trim((string) ($data['listing_url'] ?? ''));
                    $categorySlug = (string) ($data['category_slug'] ?? '');
                    $subcategorySlug = trim((string) ($data['subcategory_slug'] ?? ''));
                    $startPage = max(1, (int) ($data['start_page'] ?? 1));
                    $endPage = filled($data['end_page'] ?? null) ? max(1, (int) $data['end_page']) : null;

                    if ($listingUrl === '' || $categorySlug === '') {
                        Notification::make()
                            ->title('Не вистачає даних для запуску імпорту')
                            ->danger()
                            ->send();

                        return;
                    }

                    if ($endPage !== null && $endPage < $startPage) {
                        Notification::make()
                            ->title('Невірний діапазон сторінок')
                            ->body('Поле "По яку сторінку" має бути не менше ніж "З якої сторінки".')
                            ->danger()
                            ->send();

                        return;
                    }

                    $top20Context = Top20ImportUrl::context($listingUrl);
                    $cityCode = $top20Context['city_code'];
                    $category = Category::query()->where('slug', $categorySlug)->first();

                    if (! $category) {
                        Notification::make()
                            ->title('Категорію DOVIRA не знайдено')
                            ->danger()
                            ->send();

                        return;
                    }

                    if ($subcategorySlug !== '') {
                        $subcategoryExists = Category::query()
                            ->where('parent_id', $category->id)
                            ->where('slug', $subcategorySlug)
                            ->exists();

                        if (! $subcategoryExists) {
                            Notification::make()
                                ->title('Підкатегорія не належить вибраній категорії')
                                ->danger()
                                ->send();

                            return;
                        }
                    }

                    $subcategory = null;

                    if ($subcategorySlug !== '') {
                        $subcategory = Category::query()
                            ->where('parent_id', $category->id)
                            ->where('slug', $subcategorySlug)
                            ->first();
                    }

                    $pageRangeLabel = $endPage !== null
                        ? sprintf('стор. %d-%d', $startPage, $endPage)
                        : ($startPage > 1 ? sprintf('стор. %d+', $startPage) : 'усі сторінки');

                    $importBatch = Top20ImportBatch::query()->create([
                        'user_id' => Auth::id(),
                        'category_id' => $category->id,
                        'subcategory_id' => $subcategory?->id,
                        'name' => sprintf(
                            '%s · %s · %s · %s',
                            $subcategory?->name ? ($category->name . ' / ' . $subcategory->name) : $category->name,
                            $top20Context['city_name'] ?? ($cityCode ? Str::upper($cityCode) : 'Top20'),
                            $pageRangeLabel,
                            now()->format('d.m.Y H:i')
                        ),
                        'status' => 'queued',
                        'city_code' => $cityCode,
                        'city_name' => $top20Context['city_name'],
                        'region_name' => $top20Context['region_name'],
                        'listing_url' => $listingUrl,
                        'options' => [
                            'start_page' => $startPage,
                            'end_page' => $endPage,
                            'max_profiles' => filled($data['max_profiles'] ?? null) ? (int) $data['max_profiles'] : null,
                            'min_reviews' => max(0, (int) ($data['min_reviews'] ?? 1)),
                            'refresh_existing' => (bool) ($data['refresh_existing'] ?? false),
                            'publish_profiles' => (bool) ($data['publish_profiles'] ?? false),
                        ],
                        'started_at' => now(),
                    ]);

                    try {
                        RunTop20BulkImport::dispatch([
                            'batch_id' => $importBatch->id,
                            'category_key' => $categorySlug,
                            'category_slug' => $category->slug,
                            'listing_url' => $listingUrl,
                            'city_code' => $cityCode,
                            'city_name' => $top20Context['city_name'],
                            'region_name' => $top20Context['region_name'],
                            'start_page' => $startPage,
                            'end_page' => $endPage,
                            'max_profiles' => filled($data['max_profiles'] ?? null) ? (int) $data['max_profiles'] : null,
                            'min_reviews' => max(0, (int) ($data['min_reviews'] ?? 1)),
                            'subcategory_slug' => $subcategorySlug !== '' ? $subcategorySlug : null,
                            'refresh_existing' => (bool) ($data['refresh_existing'] ?? false),
                            'publish_profiles' => (bool) ($data['publish_profiles'] ?? false),
                            'requested_by_user_id' => Auth::id(),
                        ]);

                        app(AiEnrichmentQueueWorkerManager::class)->startManagedWorker();
                    } catch (Throwable $e) {
                        $importBatch->update([
                            'status' => 'failed',
                            'errors_count' => 1,
                            'finished_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Помилка імпорту Top20')
                            ->body(Str::limit($e->getMessage(), 180))
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Top20 імпорт поставлено в чергу')
                        ->body('Імпорт виконається у фоновому режимі. Статус і результат дивись у журналі імпортів.')
                        ->actions([
                            Action::make('open_import')
                                ->label('Відкрити імпорт')
                                ->url(Top20ImportBatchResource::getUrl('edit', ['record' => $importBatch])),
                        ])
                        ->warning()
                        ->send();
                }),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function top20CategoryOptions(): array
    {
        $categories = (array) config('top20_bulk_import.categories', []);
        $options = [];

        foreach ($categories as $key => $config) {
            if (! is_array($config)) {
                continue;
            }

            $options[(string) $key] = (string) ($config['label'] ?? $key);
        }

        return $options;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function top20CategoriesConfig(): array
    {
        return array_filter(
            (array) config('top20_bulk_import.categories', []),
            fn ($item) => is_array($item)
        );
    }

    /**
     * @return array<string, string>
     */
    protected function top20CityOptions(): array
    {
        $cities = (array) config('top20_bulk_import.cities', []);
        $options = [];

        foreach ($cities as $key => $config) {
            if (! is_array($config)) {
                continue;
            }

            $options[(string) $key] = (string) ($config['name'] ?? $key);
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    protected function top20CategoryConfig(string $key): array
    {
        $config = $this->top20CategoriesConfig()[$key] ?? null;

        return is_array($config) ? $config : [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function top20CityConfig(string $key): array
    {
        $config = ((array) config('top20_bulk_import.cities', []))[$key] ?? null;

        return is_array($config) ? $config : [];
    }

    protected function defaultTop20CityCode(): string
    {
        $keys = array_keys((array) config('top20_bulk_import.cities', []));

        return (string) ($keys[0] ?? 'od');
    }

    protected function resolveTop20ListingUrl(string $categoryKey, string $cityCode): ?string
    {
        $config = $this->top20CategoryConfig($categoryKey);
        $url = data_get($config, "listing_urls.{$cityCode}");

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    protected function exportFilteredProfiles(): StreamedResponse
    {
        $filename = 'profiles-filtered-export-' . now()->format('Ymd-His') . '.csv';

        $query = $this->getFilteredSortedTableQuery()
            ->with(['region:id,name,slug', 'primaryCategory:id,name,slug', 'services:id,name,slug']);

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $this->profileCsvHeaders());

            foreach ($query->cursor() as $profile) {
                fputcsv($handle, $this->profileToCsvRow($profile));
            }

            fclose($handle);
        }, $filename);
    }

    protected function importProfilesFromCsv(string $path, string $mode, string $delimiter, bool $hasHeader): void
    {
        $absolutePath = Storage::disk('local')->path($path);

        if (! is_file($absolutePath)) {
            Notification::make()
                ->title('Файл не знайдено')
                ->danger()
                ->send();

            return;
        }

        $handle = fopen($absolutePath, 'r');

        if (! $handle) {
            Notification::make()
                ->title('Не вдалося відкрити CSV')
                ->danger()
                ->send();

            return;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;
        $line = 0;
        $headerMap = [];
        $errorMessages = [];

        if ($hasHeader) {
            $headerRow = fgetcsv($handle, 0, $delimiter) ?: [];
            $headerMap = $this->buildHeaderMap($headerRow);
            $line++;
        }

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line++;

            if (! $this->rowHasContent($row)) {
                continue;
            }

            try {
                $payload = $hasHeader
                    ? $this->mapRowByHeader($row, $headerMap)
                    : $this->mapRowByDefaultOrder($row);

                $result = $this->upsertProfileRow($payload, $mode);

                match ($result) {
                    'created' => $created++,
                    'updated' => $updated++,
                    'skipped' => $skipped++,
                    default => $skipped++,
                };
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('profiles_csv_import_row_failed', [
                    'line' => $line,
                    'message' => $e->getMessage(),
                    'path' => $path,
                ]);

                if (count($errorMessages) < 5) {
                    $errorMessages[] = "Рядок {$line}: {$e->getMessage()}";
                }
            }
        }

        fclose($handle);

        $body = "Створено: {$created}, Оновлено: {$updated}, Пропущено: {$skipped}, Помилки: {$errors}";
        if ($errorMessages !== []) {
            $body .= "\n\n" . implode("\n", $errorMessages);
        }

        Notification::make()
            ->title('Імпорт завершено')
            ->body($body)
            ->{ $errors > 0 ? 'warning' : 'success' }()
            ->send();
    }

    protected function buildHeaderMap(array $headerRow): array
    {
        $aliases = [
            'id' => 'id',
            'назва профілю' => 'name',
            'назва' => 'name',
            'name' => 'name',
            'унікальне посилання (slug)' => 'slug',
            'slug' => 'slug',
            'type' => 'type',
            'тип профілю' => 'type',
            'тип' => 'type',
            'id категорії' => 'category_id',
            'категорія' => 'category',
            'категорія (назва)' => 'category',
            'category' => 'category',
            'category_id' => 'category_id',
            'категорія (slug)' => 'category_slug',
            'category_slug' => 'category_slug',
            'id напрямів (через |)' => 'service_ids',
            'напрями' => 'services',
            'напрями / послуги (назви через |)' => 'services',
            'services' => 'services',
            'service_ids' => 'service_ids',
            'напрями / послуги (slug через |)' => 'services_slugs',
            'services_slugs' => 'services_slugs',
            'регіон' => 'region',
            'регіон (назва)' => 'region',
            'region' => 'region',
            'id регіону' => 'region_id',
            'region_id' => 'region_id',
            'регіон (slug)' => 'region_slug',
            'region_slug' => 'region_slug',
            'місто' => 'city',
            'city' => 'city',
            'район' => 'district',
            'district' => 'district',
            'email' => 'email',
            'сайт' => 'website',
            'website' => 'website',
            'телефон' => 'phone',
            'phone' => 'phone',
            'адреса' => 'address',
            'address' => 'address',
            'логотип (url/шлях)' => 'logo_url',
            'logo_url' => 'logo_url',
            'соцмережі (json)' => 'social_links',
            'social_links_json' => 'social_links',
            'короткий опис / ai-аналіз' => 'short_description',
            'повний опис' => 'description',
            'verified (1/0)' => 'is_verified',
            'verified' => 'is_verified',
            'pro (1/0)' => 'is_pro',
            'pro' => 'is_pro',
            'featured (1/0)' => 'is_featured',
            'is_featured' => 'is_featured',
            'статус (active/hidden/draft/archived)' => 'status',
            'статус' => 'status',
            'status' => 'status',
            'опубліковано (1/0)' => 'is_published',
            'is_published' => 'is_published',
            'показувати в каталозі (1/0)' => 'show_in_catalog',
            'show_in_catalog' => 'show_in_catalog',
            'пріоритет сортування' => 'sort_priority',
            'sort_priority' => 'sort_priority',
            'short_description' => 'short_description',
            'опис' => 'description',
            'description' => 'description',
            'seo title' => 'seo_title',
            'seo_title' => 'seo_title',
            'seo description' => 'seo_description',
            'seo_description' => 'seo_description',
            'og image (url/шлях)' => 'og_image_url',
            'og_image_url' => 'og_image_url',
            'email власника' => 'owner_email',
            'owner_email' => 'owner_email',
            'id власника' => 'owner_user_id',
            'owner_user_id' => 'owner_user_id',
            'внутрішня нотатка' => 'internal_note',
            'internal_note' => 'internal_note',
        ];

        $map = [];

        foreach ($headerRow as $index => $rawHeader) {
            $normalized = Str::of((string) $rawHeader)
                ->lower()
                ->trim()
                ->replace(['"', "'", '`'], '')
                ->replace(['__', '  '], ' ')
                ->toString();

            if (array_key_exists($normalized, $aliases)) {
                $map[$index] = $aliases[$normalized];
            }
        }

        return $map;
    }

    protected function mapRowByHeader(array $row, array $headerMap): array
    {
        $payload = [];

        foreach ($headerMap as $index => $field) {
            $payload[$field] = trim((string) ($row[$index] ?? ''));
        }

        return $payload;
    }

    protected function mapRowByDefaultOrder(array $row): array
    {
        $payload = [];
        $headers = $this->profileCsvFieldKeys();

        foreach ($headers as $index => $field) {
            $payload[$field] = trim((string) ($row[$index] ?? ''));
        }

        return $payload;
    }

    protected function upsertProfileRow(array $payload, string $mode): string
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $slug = trim((string) ($payload['slug'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $website = trim((string) ($payload['website'] ?? ''));

        if ($name === '' && $slug === '' && $email === '' && $website === '') {
            return 'skipped';
        }

        $query = Profile::query();

        if (filled($payload['id'] ?? null)) {
            $query->where('id', (int) $payload['id']);
        } elseif ($slug !== '') {
            $query->where('slug', $slug);
        } elseif ($email !== '') {
            $query->where('email', $email);
        } elseif ($website !== '') {
            $query->where('website', $website);
        } else {
            return 'skipped';
        }

        $profile = $query->first();

        if ($mode === 'create' && $profile) {
            return 'skipped';
        }

        if ($mode === 'update' && ! $profile) {
            return 'skipped';
        }

        $isNew = ! $profile;
        $profile ??= new Profile();

        $profile->fill([
            'name' => $name !== '' ? $name : ($profile->name ?: 'Без назви'),
            'slug' => $isNew
                ? $this->resolveUniqueSlug($slug !== '' ? $slug : Str::slug($name ?: ('profile-' . Str::random(6))))
                : ($slug !== '' ? $slug : $profile->slug),
            'type' => in_array(($payload['type'] ?? ''), ['company', 'specialist', 'service'], true)
                ? $payload['type']
                : ($profile->type ?: 'company'),
            'description' => $this->nullable($payload['description'] ?? null, $profile->description),
            'short_description' => $this->nullable($payload['short_description'] ?? null, $profile->short_description),
            'website' => $this->nullable($payload['website'] ?? null, $profile->website),
            'email' => $this->nullable($payload['email'] ?? null, $profile->email),
            'phone' => $this->nullable($payload['phone'] ?? null, $profile->phone),
            'address' => $this->nullable($payload['address'] ?? null, $profile->address),
            'city' => $this->nullable($payload['city'] ?? null, $profile->city),
            'district' => $this->nullable($payload['district'] ?? null, $profile->district),
            'logo_url' => $this->nullable($payload['logo_url'] ?? null, $profile->logo_url),
            'social_links' => $this->jsonOrCurrent($payload['social_links'] ?? null, $profile->social_links),
            'rating_avg' => $this->numericOrCurrent($payload['rating_avg'] ?? null, $profile->rating_avg, 0, 'float'),
            'reviews_count' => $this->numericOrCurrent($payload['reviews_count'] ?? null, $profile->reviews_count, 0, 'int'),
            'views_count' => $this->numericOrCurrent($payload['views_count'] ?? null, $profile->views_count, 0, 'int'),
            'unique_views_count' => $this->numericOrCurrent($payload['unique_views_count'] ?? null, $profile->unique_views_count, 0, 'int'),
            'website_clicks_count' => $this->numericOrCurrent($payload['website_clicks_count'] ?? null, $profile->website_clicks_count, 0, 'int'),
            'contact_clicks_count' => $this->numericOrCurrent($payload['contact_clicks_count'] ?? null, $profile->contact_clicks_count, 0, 'int'),
            'popularity_score' => $this->numericOrCurrent($payload['popularity_score'] ?? null, $profile->popularity_score, 0, 'float'),
            'is_verified' => array_key_exists('is_verified', $payload) ? $this->toBool($payload['is_verified']) : $profile->is_verified,
            'is_pro' => array_key_exists('is_pro', $payload) ? $this->toBool($payload['is_pro']) : $profile->is_pro,
            'is_featured' => array_key_exists('is_featured', $payload) ? $this->toBool($payload['is_featured']) : $profile->is_featured,
            'status' => in_array(($payload['status'] ?? ''), ['active', 'hidden', 'draft', 'archived'], true)
                ? $payload['status']
                : ($profile->status ?: 'active'),
            'is_published' => array_key_exists('is_published', $payload) ? $this->toBool($payload['is_published']) : $profile->is_published,
            'show_in_catalog' => array_key_exists('show_in_catalog', $payload) ? $this->toBool($payload['show_in_catalog']) : $profile->show_in_catalog,
            'sort_priority' => $this->numericOrCurrent($payload['sort_priority'] ?? null, $profile->sort_priority, 0, 'int'),
            'recommend_percent' => $this->numericOrCurrent($payload['recommend_percent'] ?? null, $profile->recommend_percent, 0, 'int'),
            'seo_title' => $this->nullable($payload['seo_title'] ?? null, $profile->seo_title),
            'seo_description' => $this->nullable($payload['seo_description'] ?? null, $profile->seo_description),
            'og_image_url' => $this->nullable($payload['og_image_url'] ?? null, $profile->og_image_url),
            'internal_note' => $this->nullable($payload['internal_note'] ?? null, $profile->internal_note),
            'created_by_user_id' => $isNew ? Auth::id() : $profile->created_by_user_id,
            'updated_by_user_id' => Auth::id(),
        ]);

        if (! empty($payload['owner_user_id']) && is_numeric($payload['owner_user_id'])) {
            $profile->owner_user_id = (int) $payload['owner_user_id'];
        } elseif (! empty($payload['owner_email'])) {
            $profile->owner_user_id = \App\Models\User::query()->where('email', $payload['owner_email'])->value('id');
        }

        if (! empty($payload['region_id']) && is_numeric($payload['region_id'])) {
            $profile->region_id = (int) $payload['region_id'];
        } elseif (! empty($payload['region'])) {
            $profile->region_id = Region::query()
                ->where('name', $payload['region'])
                ->orWhere('slug', Str::slug($payload['region']))
                ->value('id');
        } elseif (! empty($payload['region_slug'])) {
            $profile->region_id = Region::query()
                ->where('slug', Str::slug($payload['region_slug']))
                ->value('id');
        }

        $profile->save();

            if (! empty($payload['category_id']) || ! empty($payload['category']) || ! empty($payload['category_slug'])) {
                $categoryId = null;

                if (! empty($payload['category_id']) && is_numeric($payload['category_id'])) {
                    $categoryId = Category::query()
                        ->whereKey((int) $payload['category_id'])
                        ->value('id');
                }

                if (! $categoryId) {
                    $categoryId = Category::query()
                        ->where(function ($query) use ($payload): void {
                            if (! empty($payload['category'])) {
                                $query->where('name', $payload['category'])
                                    ->orWhere('slug', Str::slug($payload['category']));
                            }

                            if (! empty($payload['category_slug'])) {
                                $query->orWhere('slug', Str::slug($payload['category_slug']));
                            }
                        })
                        ->value('id');
                }

                if ($categoryId) {
                    $rootId = CategoryHierarchy::resolveRootCategoryId($categoryId);
                    $subcategoryId = $rootId !== null && $rootId !== (int) $categoryId ? $categoryId : null;

                    CategoryHierarchy::syncProfileCategories($profile, $rootId, $subcategoryId);

                    $serviceIds = $this->resolveServiceIds(
                        (int) ($rootId ?? $categoryId),
                        (string) ($payload['services'] ?? ''),
                        (string) ($payload['services_slugs'] ?? ''),
                        (string) ($payload['service_ids'] ?? '')
                    );
                    if ($serviceIds !== []) {
                        $profile->services()->sync($serviceIds);
                    }
                }
            }

        return $isNew ? 'created' : 'updated';
    }

    protected function resolveUniqueSlug(string $baseSlug): string
    {
        $slug = Str::slug($baseSlug) ?: 'profile-' . Str::lower(Str::random(6));
        $original = $slug;
        $i = 1;

        while (Profile::query()->where('slug', $slug)->exists()) {
            $slug = $original . '-' . $i;
            $i++;
        }

        return $slug;
    }

    protected function toBool(string|int|bool|null $value): bool
    {
        return in_array(Str::lower(trim((string) $value)), ['1', 'true', 'yes', 'y', 'так'], true);
    }

    protected function nullable(mixed $value, mixed $current): mixed
    {
        if (! is_string($value)) {
            return $value ?? $current;
        }

        $trim = trim($value);

        return $trim === '' ? null : $trim;
    }

    protected function jsonOrCurrent(mixed $value, mixed $current): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $current;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return $current;
    }

    protected function resolveServiceIds(int $categoryId, string $services, string $serviceSlugs, string $serviceIdsCsv = ''): array
    {
        $ids = collect(explode('|', $serviceIdsCsv))
            ->map(fn ($id) => (int) trim($id))
            ->filter(fn ($id) => $id > 0)
            ->values();

        $byName = collect(explode('|', $services))
            ->map(fn ($name) => trim($name))
            ->filter()
            ->values();

        $bySlug = collect(explode('|', $serviceSlugs))
            ->map(fn ($slug) => Str::slug(trim($slug)))
            ->filter()
            ->values();

        if ($ids->isEmpty() && $byName->isEmpty() && $bySlug->isEmpty()) {
            return [];
        }

        return CategoryService::query()
            ->where('category_id', $categoryId)
            ->where(function ($query) use ($ids, $byName, $bySlug): void {
                if ($ids->isNotEmpty()) {
                    $query->whereIn('id', $ids->all());
                }

                if ($byName->isNotEmpty()) {
                    $query->whereIn('name', $byName->all());
                }

                if ($bySlug->isNotEmpty()) {
                    $query->orWhereIn('slug', $bySlug->all());
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function numericOrCurrent(
        mixed $value,
        mixed $current,
        int|float $default = 0,
        string $type = 'int'
    ): int|float {
        if (is_numeric($value)) {
            return $type === 'float' ? (float) $value : (int) $value;
        }

        if (is_numeric($current)) {
            return $type === 'float' ? (float) $current : (int) $current;
        }

        return $default;
    }

    protected function profileCsvHeaders(): array
    {
        return [
            'Назва профілю',
            'Категорія (назва)',
            'Категорія (slug)',
            'Напрями / послуги (назви через |)',
            'Напрями / послуги (slug через |)',
            'Регіон (назва)',
            'Регіон (slug)',
            'Місто',
            'Район',
            'Адреса',
            'Сайт',
            'Email',
            'Телефон',
            'Логотип (URL/шлях)',
            'Соцмережі (JSON)',
            'Короткий опис / AI-аналіз',
            'Повний опис',
            'Verified (1/0)',
            'PRO (1/0)',
            'Featured (1/0)',
            'Статус (active/hidden/draft/archived)',
            'Опубліковано (1/0)',
            'Показувати в каталозі (1/0)',
            'Пріоритет сортування',
            'SEO title',
            'SEO description',
            'OG image (URL/шлях)',
            'Email власника',
            'Внутрішня нотатка',
        ];
    }

    protected function profileCsvFieldKeys(): array
    {
        return [
            'name',
            'category',
            'category_slug',
            'services',
            'services_slugs',
            'region',
            'region_slug',
            'city',
            'district',
            'address',
            'website',
            'email',
            'phone',
            'logo_url',
            'social_links',
            'short_description',
            'description',
            'is_verified',
            'is_pro',
            'is_featured',
            'status',
            'is_published',
            'show_in_catalog',
            'sort_priority',
            'seo_title',
            'seo_description',
            'og_image_url',
            'owner_email',
            'internal_note',
        ];
    }

    protected function profileToCsvRow(Profile $profile): array
    {
        $primaryCategory = $profile->primaryCategory->first();
        $services = $profile->services;

        return [
            $profile->name,
            $primaryCategory?->name,
            $primaryCategory?->slug,
            $services->pluck('name')->join('|'),
            $services->pluck('slug')->join('|'),
            $profile->region?->name,
            $profile->region?->slug,
            $profile->city,
            $profile->district,
            $profile->address,
            $profile->website,
            $profile->email,
            $profile->phone,
            $profile->logo_url,
            json_encode($profile->social_links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $profile->short_description,
            $profile->description,
            $profile->is_verified ? '1' : '0',
            $profile->is_pro ? '1' : '0',
            $profile->is_featured ? '1' : '0',
            $profile->status,
            $profile->is_published ? '1' : '0',
            $profile->show_in_catalog ? '1' : '0',
            $profile->sort_priority,
            $profile->seo_title,
            $profile->seo_description,
            $profile->og_image_url,
            $profile->owner?->email,
            $profile->internal_note,
        ];
    }

    protected function rowHasContent(array $row): bool
    {
        foreach ($row as $column) {
            if (trim((string) $column) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * JSONL-пакет кандидатів на AI-досьє: активні профілі категорії без
     * досьє і без попередньої вигрузки. Вигружені позначаються
     * dossier_exported_at — повторно в пакети не потрапляють.
     */
    protected function exportDossierBatch(int $count, int $categoryId): ?StreamedResponse
    {
        $count = max(1, min(1000, $count));

        $profiles = Profile::query()
            ->where('status', 'active')
            ->whereHas('categories', fn ($q) => $q->where('categories.id', $categoryId))
            ->whereNull('dossier')
            ->whereNull('dossier_exported_at')
            ->with('categories:id,name')
            ->orderByDesc('reviews_count')
            ->orderBy('id')
            ->limit($count)
            ->get(['id', 'name', 'slug', 'city', 'address', 'phone', 'website', 'type', 'short_description', 'description']);

        if ($profiles->isEmpty()) {
            Notification::make()
                ->title('Кандидатів не лишилось')
                ->body('Усі профілі цієї категорії або вже мають досьє, або вже вигружені.')
                ->warning()
                ->send();

            return null;
        }

        Profile::query()->whereIn('id', $profiles->pluck('id'))->update(['dossier_exported_at' => now()]);

        $baseUrl = rtrim((string) config('app.url'), '/');
        $fileName = 'dossier-batch-'.now()->format('Ymd-His').'-'.$profiles->count().'.jsonl';

        return response()->streamDownload(function () use ($profiles, $baseUrl): void {
            foreach ($profiles as $p) {
                $identity = array_filter([
                    'category' => $p->categories->pluck('name')->implode(', '),
                    'address' => $p->address,
                    'phone' => $p->phone,
                    'type' => $p->type,
                    'about_excerpt' => Str::limit(trim(strip_tags((string) ($p->short_description ?: $p->description))), 200, '…') ?: null,
                ]);

                echo json_encode([
                    'lawyer_id' => $p->id,
                    'full_name' => $p->name,
                    'city' => $p->city,
                    'company_name' => $p->type === 'company' ? $p->name : null,
                    'certificate_number' => null,
                    'profile_url' => $baseUrl.'/profiles/'.$p->slug,
                    'company_url' => $p->website ?: null,
                    'additional_identity_data' => $identity === [] ? null : $identity,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
            }
        }, $fileName, ['Content-Type' => 'application/jsonl; charset=UTF-8']);
    }
}
