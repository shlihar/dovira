<?php

namespace App\Filament\Resources\AiEnrichmentBatches\Pages;

use App\Filament\Resources\AiEnrichmentBatches\AiEnrichmentBatchResource;
use App\Jobs\CollectUadvokatLawyers;
use App\Models\AiEnrichmentBatch;
use App\Models\Category;
use App\Services\Queue\AiEnrichmentQueueWorkerManager;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListAiEnrichmentBatches extends ListRecords
{
    protected static string $resource = AiEnrichmentBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Нове AI-збагачення'),
            Action::make('importUadvokat')
                ->label('Імпорт адвокатів (uadvokat)')
                ->icon('heroicon-o-scale')
                ->color('warning')
                ->form([
                    TextInput::make('city_url')
                        ->label('Посилання на місто в реєстрі uadvokat.com.ua')
                        ->placeholder('https://uadvokat.com.ua/reestr/cherkaska/cherkasi/')
                        ->helperText('Відкрий uadvokat.com.ua/reestr/, вибери область і місто, скопіюй адресу сторінки.')
                        ->required()
                        ->url(),
                    TextInput::make('start_page')
                        ->label('З якої сторінки')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->required()
                        ->helperText('На сторінці реєстру ~10 адвокатів.'),
                    TextInput::make('end_page')
                        ->label('По яку сторінку')
                        ->numeric()
                        ->minValue(1)
                        ->placeholder('До кінця міста')
                        ->helperText('Наприклад 1-3 = ~30 адвокатів. Наступний запуск продовжуй з 4-ї. Кожен адвокат = витрати на OpenAI (опис + пошук сайту й відгуків).'),
                    TextInput::make('max_lawyers')
                        ->label('Макс. адвокатів (запобіжник)')
                        ->numeric()
                        ->minValue(1)
                        ->placeholder('Без обмеження'),
                    Toggle::make('solo_only')
                        ->label('Лише одноосібники (індивідуальна адвокатська діяльність)')
                        ->default(true),
                    Toggle::make('publish_profiles')
                        ->label('Одразу публікувати створені профілі')
                        ->default(false)
                        ->helperText('Якщо вимкнено — профілі створюються чернетками і чекають на ручну перевірку.'),
                    Select::make('ai_mode')
                        ->label('AI-збагачення')
                        ->options([
                            'valid' => 'Лише валідні (авто-відбір: є на top20 або Google Maps з відгуками)',
                            'none' => 'Без AI — лише реєстрові дані',
                            'all' => 'Для всіх адвокатів (найдорожче)',
                        ])
                        ->default('valid')
                        ->required()
                        ->helperText('Всі адвокати додаються як профілі в будь-якому режимі. AI (опис, пошук сайту й відгуків) запускається лише для відібраних. Пізніше AI можна запустити вручну: список профілів → дія "AI-збагачення вибраних".'),
                    Select::make('category_slug')
                        ->label('Категорія DOVIRA')
                        ->options(fn () => Category::query()
                            ->whereNull('parent_id')
                            ->orderBy('sort_order')
                            ->orderBy('name')
                            ->pluck('name', 'slug'))
                        ->default('catalog-yurydychni-poslugy')
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
                        ->default('advokaty')
                        ->searchable()
                        ->placeholder('Без підкатегорії'),
                ])
                ->modalWidth('2xl')
                ->modalSubmitActionLabel('Запустити імпорт')
                ->action(function (array $data): void {
                    $cityUrl = trim((string) ($data['city_url'] ?? ''));

                    if (str_contains($cityUrl, '.html')) {
                        Notification::make()
                            ->title('Це посилання на окремого адвоката')
                            ->body('Встав посилання на сторінку міста зі списком адвокатів, наприклад https://uadvokat.com.ua/reestr/cherkaska/cherkasi/')
                            ->danger()
                            ->send();

                        return;
                    }

                    if (! preg_match('#^https?://(?:www\.)?uadvokat\.com\.ua/reestr/([a-z0-9-]+)/([a-z0-9-]+)/?#i', $cityUrl, $matches)) {
                        Notification::make()
                            ->title('Невірне посилання')
                            ->body('Потрібне посилання виду https://uadvokat.com.ua/reestr/{область}/{місто}/ — саме на місто, а не на область.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $regionSlug = mb_strtolower($matches[1]);
                    $citySlug = mb_strtolower($matches[2]);

                    $categorySlug = (string) ($data['category_slug'] ?? '');
                    $subcategorySlug = trim((string) ($data['subcategory_slug'] ?? ''));
                    $category = Category::query()->where('slug', $categorySlug)->first();

                    if (! $category) {
                        Notification::make()
                            ->title('Категорію DOVIRA не знайдено')
                            ->danger()
                            ->send();

                        return;
                    }

                    $subcategory = $subcategorySlug !== ''
                        ? Category::query()->where('parent_id', $category->id)->where('slug', $subcategorySlug)->first()
                        : null;

                    $startPage = max(1, (int) ($data['start_page'] ?? 1));
                    $endPage = filled($data['end_page'] ?? null) ? max(1, (int) $data['end_page']) : null;
                    $maxLawyers = filled($data['max_lawyers'] ?? null) ? max(1, (int) $data['max_lawyers']) : null;
                    $soloOnly = (bool) ($data['solo_only'] ?? true);

                    if ($endPage !== null && $endPage < $startPage) {
                        Notification::make()
                            ->title('Невірний діапазон сторінок')
                            ->body('Поле "По яку сторінку" має бути не менше ніж "З якої сторінки".')
                            ->danger()
                            ->send();

                        return;
                    }

                    $pageRangeLabel = $endPage !== null
                        ? sprintf('стор. %d-%d', $startPage, $endPage)
                        : ($startPage > 1 ? sprintf('стор. %d+', $startPage) : 'усі сторінки');

                    $batch = AiEnrichmentBatch::create([
                        'user_id' => Auth::id(),
                        'default_category_id' => $subcategory?->id ?? $category->id,
                        'name' => sprintf('Адвокати uadvokat · %s/%s · %s · збір даних… · %s', $regionSlug, $citySlug, $pageRangeLabel, now()->format('d.m.Y H:i')),
                        'source_type' => 'uadvokat',
                        // Не 'queued': інакше відновлювач черги передчасно запустить
                        // ProcessAiEnrichmentBatch до того, як збір наповнить input_text.
                        'status' => 'collecting',
                        'default_country' => 'Україна',
                        'language' => 'uk',
                        'options' => [
                            'find_reviews' => true,
                            'uadvokat_region' => $regionSlug,
                            'uadvokat_city' => $citySlug,
                            'start_page' => $startPage,
                            'end_page' => $endPage,
                            'solo_only' => $soloOnly,
                            'publish_profiles' => (bool) ($data['publish_profiles'] ?? false),
                            'ai_mode' => (string) ($data['ai_mode'] ?? 'valid'),
                            'max_lawyers' => $maxLawyers,
                            'requested_by_user_id' => Auth::id(),
                        ],
                        'total_items' => 0,
                    ]);

                    CollectUadvokatLawyers::dispatch(
                        (int) $batch->id,
                        $regionSlug,
                        $citySlug,
                        $startPage,
                        $endPage,
                        $maxLawyers,
                        $soloOnly,
                        (string) ($data['ai_mode'] ?? 'valid')
                    );
                    app(AiEnrichmentQueueWorkerManager::class)->startManagedWorker();

                    Notification::make()
                        ->title('Імпорт адвокатів поставлено в чергу')
                        ->body('Спершу збираються дані з uadvokat (кілька хвилин), далі batch автоматично піде в AI-збагачення. Прогрес — у цьому журналі.')
                        ->actions([
                            Action::make('open_batch')
                                ->label('Відкрити batch')
                                ->url(AiEnrichmentBatchResource::getUrl('edit', ['record' => $batch])),
                        ])
                        ->warning()
                        ->send();
                }),
        ];
    }
}
