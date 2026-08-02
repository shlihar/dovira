<?php

namespace App\Filament\Resources\Profiles\Tables;

use App\Filament\Resources\Profiles\ProfileResource;
use App\Jobs\ProcessAiEnrichmentBatch;
use App\Jobs\RefreshProfileReviewAiAnalysis;
use App\Models\AiEnrichmentBatch;
use App\Models\Category;
use App\Models\CategoryService;
use App\Support\CategoryHierarchy;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->persistSortInSession()
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->persistColumnSearchesInSession()
            ->extraAttributes(['class' => 'fi-ta-profiles'])
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->width('1%')
                    ->grow(false)
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Назва профілю')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $search = trim($search);
                        if ($search === '') {
                            return $query;
                        }

                        $variants = collect([
                            $search,
                            mb_strtolower($search, 'UTF-8'),
                            mb_strtoupper($search, 'UTF-8'),
                            Str::ucfirst(mb_strtolower($search, 'UTF-8')),
                            mb_convert_case($search, MB_CASE_TITLE, 'UTF-8'),
                        ])
                            ->filter(fn ($value) => is_string($value) && $value !== '')
                            ->unique()
                            ->values();

                        return $query->where(function (Builder $inner) use ($variants): void {
                            foreach ($variants as $variant) {
                                $term = '%' . $variant . '%';
                                $inner->orWhere('name', 'like', $term)
                                    ->orWhere('slug', 'like', $term)
                                    ->orWhere('email', 'like', $term)
                                    ->orWhere('website', 'like', $term)
                                    ->orWhere('phone', 'like', $term)
                                    ->orWhere('city', 'like', $term);
                            }
                        });
                    })
                    ->description(fn ($record) => $record->slug)
                    ->weight('semibold')
                    ->width('20rem')
                    ->grow(false)
                    ->limit(36),
                TextColumn::make('primaryCategory.name')
                    ->label('Категорія / підкатегорія')
                    ->placeholder('—')
                    ->width('18rem')
                    ->grow(false)
                    ->state(function ($record) {
                        $primary = $record->primaryCategory->first();

                        if (! $primary) {
                            return '—';
                        }

                        return $primary->parent
                            ? ($primary->parent->name . ' / ' . $primary->name)
                            : $primary->name;
                    }),
                TextColumn::make('services_list')
                    ->label('Напрями')
                    ->state(fn ($record) => $record->services->pluck('name')->take(3)->join(', '))
                    ->placeholder('—')
                    ->tooltip(fn ($record) => $record->services->pluck('name')->join(', '))
                    ->width('10rem')
                    ->grow(false)
                    ->limit(40),
                TextColumn::make('region_city')
                    ->label('Регіон / місто')
                    ->width('16rem')
                    ->grow(false)
                    ->state(fn ($record) => trim(($record->region?->name ?? '—') . ' / ' . ($record->city ?: '—'))),
                TextColumn::make('rating_avg')
                    ->label('Рейтинг')
                    ->width('1%')
                    ->grow(false)
                    ->numeric(decimalPlaces: 1)
                    ->sortable(),
                TextColumn::make('reviews_count')
                    ->label('К-сть відгуків')
                    ->width('1%')
                    ->grow(false)
                    ->numeric()
                    ->sortable(),
                TextColumn::make('views_count')
                    ->label('Перегляди')
                    ->width('1%')
                    ->grow(false)
                    ->numeric()
                    ->sortable(),
                TextColumn::make('website_clicks_count')
                    ->label('Кліки на сайт')
                    ->width('1%')
                    ->grow(false)
                    ->numeric()
                    ->sortable(),
                TextColumn::make('contact_clicks_count')
                    ->label('Кліки контактів')
                    ->width('1%')
                    ->grow(false)
                    ->numeric()
                    ->sortable(),
                TextColumn::make('ctr')
                    ->label('CTR')
                    ->width('1%')
                    ->grow(false)
                    ->state(fn ($record) => $record->views_count > 0
                        ? round((($record->website_clicks_count + $record->contact_clicks_count) / $record->views_count) * 100, 2) . '%'
                        : '0%')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw(
                            '(CASE WHEN views_count > 0 THEN ((website_clicks_count + contact_clicks_count) * 100.0 / views_count) ELSE 0 END) ' . $direction
                        );
                    }),
                TextColumn::make('popularity_score')
                    ->label('Популярність')
                    ->width('1%')
                    ->grow(false)
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                IconColumn::make('is_verified')
                    ->label('Verified')
                    ->width('1%')
                    ->grow(false)
                    ->boolean(),
                IconColumn::make('owner_verified_status')
                    ->label('Синя галочка')
                    ->width('1%')
                    ->grow(false)
                    ->boolean()
                    ->state(fn ($record) => (bool) ($record->is_owner_verified || $record->has_approved_claim || $record->owner_user_id)),
                IconColumn::make('is_pro')
                    ->label('PRO')
                    ->width('1%')
                    ->grow(false)
                    ->boolean(),
                TextColumn::make('status')
                    ->label('Статус видимості')
                    ->width('1%')
                    ->grow(false)
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'active' => 'Опубліковано',
                        'hidden' => 'Приховано',
                        'draft' => 'Чернетка',
                        'archived' => 'Архів',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'hidden' => 'warning',
                        'draft' => 'gray',
                        'archived' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('completeness')
                    ->label('Повнота профілю')
                    ->width('1%')
                    ->grow(false)
                    ->state(fn ($record) => $record->completenessPercent() . '%')
                    ->badge()
                    ->color(fn ($record) => match (true) {
                        $record->completenessPercent() >= 85 => 'success',
                        $record->completenessPercent() >= 60 => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('created_at')
                    ->label('Дата створення')
                    ->width('1%')
                    ->grow(false)
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Категорія')
                    ->options(fn () => once(fn () => CategoryHierarchy::rootOptions()))
                    ->query(function (Builder $query, array $data): Builder {
                        $categoryId = (int) ($data['value'] ?? 0);
                        if ($categoryId <= 0) {
                            return $query;
                        }

                        $ids = CategoryHierarchy::descendantIds($categoryId);

                        return $query->whereHas('categories', fn (Builder $categories) => $categories->whereIn('categories.id', $ids));
                    }),
                SelectFilter::make('subcategory')
                    ->label('Підкатегорія')
                    ->options(fn () => once(fn () => Category::query()
                        ->whereNotNull('parent_id')
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()))
                    ->query(function (Builder $query, array $data): Builder {
                        $categoryId = (int) ($data['value'] ?? 0);
                        if ($categoryId <= 0) {
                            return $query;
                        }

                        return $query->whereHas('categories', fn (Builder $categories) => $categories->whereKey($categoryId));
                    }),
                SelectFilter::make('direction')
                    ->label('Напрям')
                    ->options(fn () => once(fn () => CategoryService::query()->orderBy('name')->pluck('name', 'id')->all()))
                    ->query(function (Builder $query, array $data): Builder {
                        $serviceId = $data['value'] ?? null;
                        if (! $serviceId) {
                            return $query;
                        }

                        return $query->whereHas('services', fn (Builder $serviceQuery) => $serviceQuery->where('category_services.id', $serviceId));
                    }),
                SelectFilter::make('region_id')
                    ->label('Регіон')
                    ->relationship('region', 'name'),
                TernaryFilter::make('is_verified')->label('Verified'),
                TernaryFilter::make('owner_verified')
                    ->label('Синя галочка')
                    ->queries(
                        true: fn (Builder $query) => $query->where(function (Builder $inner) {
                            $inner->where('is_owner_verified', true)
                                ->orWhereHas('claims', fn (Builder $claims) => $claims->where('status', 'approved'));
                        }),
                        false: fn (Builder $query) => $query->where('is_owner_verified', false)
                            ->whereDoesntHave('claims', fn (Builder $claims) => $claims->where('status', 'approved')),
                        blank: fn (Builder $query) => $query,
                    ),
                TernaryFilter::make('is_pro')->label('PRO'),
                SelectFilter::make('status')
                    ->label('Статус видимості')
                    ->options([
                        'active' => 'Опубліковано',
                        'hidden' => 'Приховано',
                        'draft' => 'Чернетка',
                        'archived' => 'Архів',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if ($value === 'archived') {
                            return $query->where('status', 'archived');
                        }

                        if (blank($value)) {
                            return $query->where(function (Builder $inner) {
                                $inner->whereNull('status')
                                    ->orWhere('status', '!=', 'archived');
                            });
                        }

                        return $query->where('status', $value);
                    }),
                SelectFilter::make('import_source')
                    ->label('Джерело імпорту')
                    ->options([
                        'top20_import' => 'Top20 імпорт',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = (string) ($data['value'] ?? '');

                        if ($value === '') {
                            return $query;
                        }

                        return $query->whereHas('dataSources', fn (Builder $sources) => $sources->where('source_type', $value));
                    }),
                SelectFilter::make('has_reviews')
                    ->label('Наявність відгуків')
                    ->options([
                        'yes' => 'Є відгуки',
                        'no' => 'Без відгуків',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return match ($value) {
                            'yes' => $query->where('reviews_count', '>', 0),
                            'no' => $query->where('reviews_count', 0),
                            default => $query,
                        };
                    }),
                SelectFilter::make('rating')
                    ->label('Рейтинг')
                    ->options([
                        '5' => '5.0',
                        '4.5' => '4.5+',
                        '4' => '4.0+',
                        '3' => '3.0+',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = (float) ($data['value'] ?? 0);

                        return $value > 0 ? $query->where('rating_avg', '>=', $value) : $query;
                    }),
                SelectFilter::make('completeness')
                    ->label('Повнота профілю')
                    ->options([
                        'high' => '85%+',
                        'medium' => '60-84%',
                        'low' => '< 60%',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'high' => $query->whereNotNull('description')
                                ->whereNotNull('short_description')
                                ->whereNotNull('website')
                                ->whereNotNull('email')
                                ->whereNotNull('phone')
                                ->whereNotNull('logo_url')
                                ->whereNotNull('banner_url')
                                ->whereNotNull('seo_title')
                                ->whereNotNull('seo_description'),
                            'medium' => $query->whereNotNull('description')
                                ->whereNotNull('short_description')
                                ->whereNotNull('logo_url'),
                            'low' => $query->where(function (Builder $inner) {
                                $inner->whereNull('description')
                                    ->orWhereNull('short_description')
                                    ->orWhereNull('logo_url');
                            }),
                            default => $query,
                        };
                    }),
                SelectFilter::make('sort_by')
                    ->label('Сортування')
                    ->options([
                        'most_viewed' => 'Найбільше переглядів',
                        'most_clicked' => 'Найбільше кліків',
                        'highest_ctr' => 'Найвищий CTR',
                        'most_popular' => 'Найпопулярніші',
                        'most_reviewed' => 'Найбільше відгуків',
                        'highest_rated' => 'Найвищий рейтинг',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'most_viewed' => $query->orderByDesc('views_count'),
                            'most_clicked' => $query->orderByRaw('(website_clicks_count + contact_clicks_count) DESC'),
                            'highest_ctr' => $query->orderByRaw('(CASE WHEN views_count > 0 THEN ((website_clicks_count + contact_clicks_count) * 100.0 / views_count) ELSE 0 END) DESC'),
                            'most_popular' => $query->orderByDesc('popularity_score'),
                            'most_reviewed' => $query->orderByDesc('reviews_count'),
                            'highest_rated' => $query->orderByDesc('rating_avg'),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                Action::make('public')
                    ->label('Публічна сторінка')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record) => route('profile.show', ['slug' => $record->slug]))
                    ->openUrlInNewTab(),
                EditAction::make()->label('Редагувати'),
                DeleteAction::make()
                    ->label('Видалити')
                    ->requiresConfirmation(),
            ])
            ->recordUrl(fn ($record) => ProfileResource::getUrl('edit', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('publish')
                        ->label('Опублікувати')
                        ->icon('heroicon-o-eye')
                        ->action(fn (Collection $records) => $records->each->update([
                            'status' => 'active',
                            'is_published' => true,
                            'show_in_catalog' => true,
                        ])),
                    BulkAction::make('hide')
                        ->label('Приховати')
                        ->icon('heroicon-o-eye-slash')
                        ->action(fn (Collection $records) => $records->each->update([
                            'status' => 'hidden',
                            'is_published' => false,
                            'show_in_catalog' => false,
                        ])),
                    BulkAction::make('ai_enrich_selected')
                        ->label('AI-збагачення вибраних')
                        ->icon('heroicon-o-cpu-chip')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Запустити AI-збагачення для вибраних профілів?')
                        ->modalDescription('Для кожного профілю AI згенерує опис, пошукає офіційний сайт (контакти звідти мають пріоритет) та відгуки. Це витрачає кошти OpenAI за кожен профіль.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $profileIds = $records->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

                            if ($profileIds === []) {
                                return;
                            }

                            $batch = AiEnrichmentBatch::create([
                                'user_id' => auth()->id(),
                                'name' => sprintf('AI-збагачення вибраних · %d профілів · %s', count($profileIds), now()->format('d.m.Y H:i')),
                                'source_type' => 'selected_profiles',
                                'status' => 'queued',
                                'default_country' => 'Україна',
                                'language' => 'uk',
                                'options' => [
                                    'find_reviews' => true,
                                    'profile_ids' => $profileIds,
                                    'requested_by_user_id' => auth()->id(),
                                ],
                                'total_items' => count($profileIds),
                            ]);

                            ProcessAiEnrichmentBatch::dispatchAndEnsureWorker((int) $batch->id);

                            Notification::make()
                                ->title(sprintf('AI-збагачення поставлено в чергу: %d профілів', count($profileIds)))
                                ->body('Прогрес — у розділі "AI збагачення профілів", batch #' . $batch->id . '.')
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('refresh_ai_review_analysis')
                        ->label('Оновити AI-аналіз відгуків')
                        ->icon('heroicon-o-sparkles')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $processed = 0;

                            $records->each(function ($profile) use (&$processed): void {
                                if (! $profile?->id) {
                                    return;
                                }

                                RefreshProfileReviewAiAnalysis::dispatch((int) $profile->id)->afterCommit();
                                $processed++;
                            });

                            Notification::make()
                                ->title("AI-аналіз поставлено в чергу: {$processed}")
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('set_verified')
                        ->label('Позначити verified')
                        ->icon('heroicon-o-check-badge')
                        ->action(fn (Collection $records) => $records->each->update(['is_verified' => true])),
                    BulkAction::make('unset_verified')
                        ->label('Зняти verified')
                        ->icon('heroicon-o-x-circle')
                        ->action(fn (Collection $records) => $records->each->update(['is_verified' => false])),
                    BulkAction::make('enable_pro')
                        ->label('Увімкнути PRO')
                        ->icon('heroicon-o-sparkles')
                        ->action(fn (Collection $records) => $records->each->update(['is_pro' => true])),
                    BulkAction::make('disable_pro')
                        ->label('Вимкнути PRO')
                        ->icon('heroicon-o-no-symbol')
                        ->action(fn (Collection $records) => $records->each->update(['is_pro' => false])),
                    BulkAction::make('change_category')
                        ->label('Змінити категорію')
                        ->icon('heroicon-o-tag')
                        ->form([
                            Select::make('category_id')
                                ->label('Нова категорія')
                                ->required()
                                ->options(fn () => CategoryHierarchy::rootOptions())
                                ->live(),
                            Select::make('subcategory_id')
                                ->label('Нова підкатегорія')
                                ->options(fn (callable $get) => CategoryHierarchy::childOptions((int) $get('category_id')))
                                ->placeholder('Без підкатегорії'),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $categoryId = (int) $data['category_id'];
                            $subcategoryId = isset($data['subcategory_id']) ? (int) $data['subcategory_id'] : null;

                            $records->each(function ($profile) use ($categoryId, $subcategoryId): void {
                                CategoryHierarchy::syncProfileCategories($profile, $categoryId, $subcategoryId);
                            });
                        }),
                    BulkAction::make('archive')
                        ->label('Архівувати')
                        ->icon('heroicon-o-archive-box')
                        ->action(fn (Collection $records) => $records->each->update(['status' => 'archived', 'show_in_catalog' => false])),
                    DeleteBulkAction::make()
                        ->label('Видалити')
                        ->requiresConfirmation(),
                    BulkAction::make('export')
                        ->label('Експортувати вибрані профілі')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function (Collection $records): StreamedResponse {
                            $filename = 'profiles-export-' . now()->format('Ymd-His') . '.csv';

                            return response()->streamDownload(function () use ($records): void {
                                $handle = fopen('php://output', 'w');
                                fwrite($handle, "\xEF\xBB\xBF");
                                fputcsv($handle, [
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
                                ]);

                                foreach ($records as $profile) {
                                    $profile->loadMissing(['primaryCategory:id,name,slug', 'services:id,name,slug', 'region:id,name,slug', 'owner:id,email']);
                                    $primaryCategory = $profile->primaryCategory->first();
                                    $services = $profile->services;

                                    fputcsv($handle, [
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
                                    ]);
                                }

                                fclose($handle);
                            }, $filename);
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->emptyStateHeading('Профілі не знайдені')
            ->emptyStateDescription('Додайте перший профіль або змініть фільтри.')
            ->striped();
    }
}
