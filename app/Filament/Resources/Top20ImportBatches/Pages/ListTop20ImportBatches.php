<?php

namespace App\Filament\Resources\Top20ImportBatches\Pages;

use App\Filament\Resources\Top20ImportBatches\Top20ImportBatchResource;
use App\Jobs\RunTop20BulkImport;
use App\Models\Category;
use App\Models\Top20ImportBatch;
use App\Services\Top20BulkImportService;
use App\Services\Queue\AiEnrichmentQueueWorkerManager;
use App\Support\Top20ImportUrl;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

class ListTop20ImportBatches extends ListRecords
{
    protected static string $resource = Top20ImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importTop20')
                ->label('Запустити імпорт Top20')
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
                        ->default(1)
                        ->minValue(0)
                        ->helperText('0 = імпортувати також профілі без відгуків.')
                        ->required(),
                    Toggle::make('refresh_existing')
                        ->label('Оновлювати існуючі поля профілю')
                        ->default(false),
                    Toggle::make('publish_profiles')
                        ->label('Одразу публікувати нові профілі')
                        ->default(false)
                        ->helperText('Якщо вимкнено, нові профілі будуть доступні лише в цьому журналі імпорту до публікації.'),
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
                        ->body('Імпорт виконається у фоновому режимі. Статус і результат дивись у цьому журналі.')
                        ->actions([
                            Action::make('open_import')
                                ->label('Відкрити імпорт')
                                ->url(Top20ImportBatchResource::getUrl('edit', ['record' => $importBatch])),
                        ])
                        ->warning()
                        ->send();
                }),
            Action::make('massImportTop20')
                ->label('Масовий імпорт (список)')
                ->icon('heroicon-o-queue-list')
                ->color('warning')
                ->form([
                    Textarea::make('import_list')
                        ->label('Список імпортів')
                        ->rows(14)
                        ->required()
                        ->placeholder("https://top20.ua/od/biznes-poslugi/agenstva-neruhomosti/\tНерухомість\tАгентства нерухомості\nhttps://top20.ua/kyiv/dim-i-pobut/zoomagazini-veterinarni-kliniki/\tТварини\tВетеринарні клініки")
                        ->helperText('Один імпорт на рядок: посилання Top20, категорія DOVIRA, підкатегорія (опційно). Стовпці розділяються табом (просто вставте рядки скопійовані з Excel/Google Sheets) або «;». Категорію можна вказувати назвою або slug-ом. Місто визначається з посилання автоматично.'),
                    TextInput::make('min_reviews')
                        ->label('Мін. кількість відгуків (для всіх рядків)')
                        ->numeric()
                        ->default(1)
                        ->minValue(0)
                        ->helperText('0 = імпортувати також профілі без відгуків.')
                        ->required(),
                    Toggle::make('refresh_existing')
                        ->label('Оновлювати існуючі поля профілю')
                        ->default(false),
                    Toggle::make('publish_profiles')
                        ->label('Одразу публікувати нові профілі')
                        ->default(false),
                ])
                ->modalWidth('3xl')
                ->modalSubmitActionLabel('Поставити все в чергу')
                ->action(function (array $data): void {
                    $parsed = $this->parseMassImportRows((string) ($data['import_list'] ?? ''));

                    if ($parsed['errors'] !== []) {
                        Notification::make()
                            ->title('Список містить помилки — нічого не запущено')
                            ->body(implode("\n", array_slice($parsed['errors'], 0, 8))
                                . (count($parsed['errors']) > 8 ? "\n… та ще " . (count($parsed['errors']) - 8) : ''))
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    if ($parsed['items'] === []) {
                        Notification::make()
                            ->title('Список порожній')
                            ->danger()
                            ->send();

                        return;
                    }

                    $queued = 0;
                    $failed = [];

                    foreach ($parsed['items'] as $item) {
                        try {
                            $this->queueTop20Import(
                                $item['listing_url'],
                                $item['category'],
                                $item['subcategory'],
                                [
                                    'min_reviews' => max(0, (int) ($data['min_reviews'] ?? 1)),
                                    'refresh_existing' => (bool) ($data['refresh_existing'] ?? false),
                                    'publish_profiles' => (bool) ($data['publish_profiles'] ?? false),
                                ]
                            );
                            $queued++;
                        } catch (Throwable $e) {
                            $failed[] = $item['listing_url'] . ' — ' . Str::limit($e->getMessage(), 120);
                        }
                    }

                    app(AiEnrichmentQueueWorkerManager::class)->startManagedWorker();

                    if ($failed !== []) {
                        Notification::make()
                            ->title(sprintf('У чергу поставлено %d імпортів, %d не вдалося', $queued, count($failed)))
                            ->body(implode("\n", array_slice($failed, 0, 5)))
                            ->warning()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(sprintf('У чергу поставлено %d імпортів', $queued))
                        ->body('Вони виконаються по черзі у фоновому режимі. Прогрес кожного дивись у цьому журналі.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * @return array{
     *   items: array<int, array{listing_url:string, category:Category, subcategory:?Category}>,
     *   errors: array<int, string>
     * }
     */
    private function parseMassImportRows(string $raw): array
    {
        $items = [];
        $errors = [];
        $seenUrls = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $index => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $rowLabel = 'Рядок ' . ($index + 1);
            $hasDelimiter = str_contains($line, "\t") || str_contains($line, ';') || str_contains($line, '|');

            if ($hasDelimiter) {
                $columns = str_contains($line, "\t")
                    ? explode("\t", $line)
                    : (preg_split('/[;|]/', $line) ?: []);
                $columns = array_values(array_filter(array_map('trim', $columns), fn (string $c): bool => $c !== ''));

                $listingUrl = (string) ($columns[0] ?? '');
                if (! preg_match('#^https?://(www\.)?top20\.ua/#i', $listingUrl)) {
                    $errors[] = $rowLabel . ': перший стовпець має бути посиланням на top20.ua';
                    continue;
                }

                if (! isset($columns[1])) {
                    $errors[] = $rowLabel . ': не вказано категорію DOVIRA';
                    continue;
                }

                $category = $this->resolveCategoryBySlugOrName((string) $columns[1], null);
                if (! $category) {
                    $errors[] = $rowLabel . ': категорію «' . $columns[1] . '» не знайдено';
                    continue;
                }

                $subcategory = null;
                if (isset($columns[2]) && $columns[2] !== '') {
                    $subcategory = $this->resolveCategoryBySlugOrName((string) $columns[2], (int) $category->id);
                    if (! $subcategory) {
                        $errors[] = $rowLabel . ': підкатегорію «' . $columns[2] . '» не знайдено в категорії «' . $category->name . '»';
                        continue;
                    }
                }
            } else {
                // Без табів/;/| — рядок набраний вручну через пробіли (Tab у textarea
                // переводить фокус, а не вставляє символ табуляції). Виокремлюємо URL
                // як перший "токен без пробілів", а решту тексту зіставляємо з
                // реальними назвами категорій/підкатегорій у базі.
                if (! preg_match('#^(\S+)\s*(.*)$#su', $line, $lineMatch)) {
                    $errors[] = $rowLabel . ': не вдалося розібрати рядок';
                    continue;
                }

                $listingUrl = trim((string) $lineMatch[1]);
                if (! preg_match('#^https?://(www\.)?top20\.ua/#i', $listingUrl)) {
                    $errors[] = $rowLabel . ': перший стовпець має бути посиланням на top20.ua';
                    continue;
                }

                $remainder = trim((string) $lineMatch[2]);
                if ($remainder === '') {
                    $errors[] = $rowLabel . ': не вказано категорію DOVIRA';
                    continue;
                }

                $resolved = $this->resolveCategoryAndSubcategoryFromFreeText($remainder);
                if ($resolved['category'] === null) {
                    $errors[] = $rowLabel . ': не вдалося розпізнати категорію в тексті «' . $remainder . '». Перевір назву або розділи поля символом «;».';
                    continue;
                }

                if ($remainder !== '' && $resolved['unmatchedSubcategoryText'] !== null) {
                    $errors[] = $rowLabel . ': підкатегорію «' . $resolved['unmatchedSubcategoryText'] . '» не знайдено в категорії «' . $resolved['category']->name . '»';
                    continue;
                }

                $category = $resolved['category'];
                $subcategory = $resolved['subcategory'];
            }

            if (isset($seenUrls[$listingUrl])) {
                $errors[] = $rowLabel . ': це посилання вже є у списку вище';
                continue;
            }
            $seenUrls[$listingUrl] = true;

            $items[] = [
                'listing_url' => $listingUrl,
                'category' => $category,
                'subcategory' => $subcategory,
            ];
        }

        return ['items' => $items, 'errors' => $errors];
    }

    /**
     * Зіставляє довільний текст (без розділювачів) з реальною категорією й
     * підкатегорією DOVIRA: шукає найдовшу назву топ-категорії, що є префіксом
     * тексту, а рештою намагається знайти точну назву підкатегорії.
     *
     * @return array{category: ?Category, subcategory: ?Category, unmatchedSubcategoryText: ?string}
     */
    private function resolveCategoryAndSubcategoryFromFreeText(string $text): array
    {
        $normalizedText = mb_strtolower(trim($text));

        $bestCategory = null;
        $bestCategoryNameLength = 0;

        foreach (Category::query()->whereNull('parent_id')->get(['id', 'name', 'slug']) as $candidate) {
            $candidateName = mb_strtolower(trim((string) $candidate->name));
            if ($candidateName === '' || ! str_starts_with($normalizedText, $candidateName)) {
                continue;
            }

            $nextChar = mb_substr($normalizedText, mb_strlen($candidateName), 1);
            if ($nextChar !== '' && $nextChar !== ' ') {
                continue;
            }

            if (mb_strlen($candidateName) > $bestCategoryNameLength) {
                $bestCategory = $candidate;
                $bestCategoryNameLength = mb_strlen($candidateName);
            }
        }

        if ($bestCategory === null) {
            // Однослівний текст без пробілів — спробуємо як slug/назву напряму.
            if (! str_contains(trim($text), ' ')) {
                $bestCategory = $this->resolveCategoryBySlugOrName(trim($text), null);
            }

            if ($bestCategory === null) {
                return ['category' => null, 'subcategory' => null, 'unmatchedSubcategoryText' => null];
            }

            return ['category' => $bestCategory, 'subcategory' => null, 'unmatchedSubcategoryText' => null];
        }

        $subcategoryText = trim(mb_substr($text, $bestCategoryNameLength));
        if ($subcategoryText === '') {
            return ['category' => $bestCategory, 'subcategory' => null, 'unmatchedSubcategoryText' => null];
        }

        $normalizedSubcategoryText = mb_strtolower($subcategoryText);
        $subcategory = Category::query()
            ->where('parent_id', $bestCategory->id)
            ->get(['id', 'name', 'slug'])
            ->first(fn (Category $sub): bool => mb_strtolower(trim((string) $sub->name)) === $normalizedSubcategoryText
                || mb_strtolower(trim((string) $sub->slug)) === $normalizedSubcategoryText);

        if ($subcategory === null) {
            return ['category' => $bestCategory, 'subcategory' => null, 'unmatchedSubcategoryText' => $subcategoryText];
        }

        return ['category' => $bestCategory, 'subcategory' => $subcategory, 'unmatchedSubcategoryText' => null];
    }

    private function resolveCategoryBySlugOrName(string $value, ?int $parentId): ?Category
    {
        $scoped = fn () => Category::query()->when(
            $parentId === null,
            fn ($query) => $query->whereNull('parent_id'),
            fn ($query) => $query->where('parent_id', $parentId)
        );

        return $scoped()->where('slug', $value)->first()
            ?? $scoped()->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])->first();
    }

    /**
     * @param  array{min_reviews:int, refresh_existing:bool, publish_profiles:bool}  $options
     */
    private function queueTop20Import(string $listingUrl, Category $category, ?Category $subcategory, array $options): Top20ImportBatch
    {
        $top20Context = Top20ImportUrl::context($listingUrl);
        $cityCode = $top20Context['city_code'];

        $importBatch = Top20ImportBatch::query()->create([
            'user_id' => Auth::id(),
            'category_id' => $category->id,
            'subcategory_id' => $subcategory?->id,
            'name' => sprintf(
                '%s · %s · %s · %s',
                $subcategory?->name ? ($category->name . ' / ' . $subcategory->name) : $category->name,
                $top20Context['city_name'] ?? ($cityCode ? Str::upper($cityCode) : 'Top20'),
                'усі сторінки',
                now()->format('d.m.Y H:i')
            ),
            'status' => 'queued',
            'city_code' => $cityCode,
            'city_name' => $top20Context['city_name'],
            'region_name' => $top20Context['region_name'],
            'listing_url' => $listingUrl,
            'options' => [
                'start_page' => 1,
                'end_page' => null,
                'max_profiles' => null,
                'min_reviews' => $options['min_reviews'],
                'refresh_existing' => $options['refresh_existing'],
                'publish_profiles' => $options['publish_profiles'],
            ],
            'started_at' => now(),
        ]);

        try {
            RunTop20BulkImport::dispatch([
                'batch_id' => $importBatch->id,
                'category_key' => $category->slug,
                'category_slug' => $category->slug,
                'listing_url' => $listingUrl,
                'city_code' => $cityCode,
                'city_name' => $top20Context['city_name'],
                'region_name' => $top20Context['region_name'],
                'start_page' => 1,
                'end_page' => null,
                'max_profiles' => null,
                'min_reviews' => $options['min_reviews'],
                'subcategory_slug' => $subcategory?->slug,
                'refresh_existing' => $options['refresh_existing'],
                'publish_profiles' => $options['publish_profiles'],
                'requested_by_user_id' => Auth::id(),
            ]);
        } catch (Throwable $e) {
            $importBatch->update([
                'status' => 'failed',
                'errors_count' => 1,
                'finished_at' => now(),
            ]);

            throw $e;
        }

        return $importBatch;
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
}
