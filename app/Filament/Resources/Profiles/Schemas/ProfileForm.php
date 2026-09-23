<?php

namespace App\Filament\Resources\Profiles\Schemas;

use App\Models\Category;
use App\Models\CategoryService;
use App\Support\CategoryHierarchy;
use App\Support\RegionCityDirectory;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('profile_tabs')
                    ->persistTabInQueryString('profile_tab')
                    ->tabs([
                        Tab::make('Основна інформація')
                            ->schema([
                                Section::make('Основна інформація')
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('Назва профілю')
                                            ->required()
                                            ->maxLength(255)
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Str::slug((string) $state))),
                                        TextInput::make('slug')
                                            ->required()
                                            ->alphaDash()
                                            ->unique(ignoreRecord: true),
                                        Select::make('category_id')
                                            ->label('Категорія')
                                            ->options(fn () => CategoryHierarchy::rootOptions())
                                            ->searchable()
                                            ->preload()
                                            ->live(),
                                        Select::make('subcategory_id')
                                            ->label('Підкатегорія')
                                            ->options(fn (Get $get) => CategoryHierarchy::childOptions((int) $get('category_id')))
                                            ->searchable()
                                            ->preload()
                                            ->visible(fn (Get $get) => filled($get('category_id')))
                                            ->placeholder('Оберіть підкатегорію'),
                                        Select::make('service_ids')
                                            ->label('Напрями / послуги')
                                            ->options(fn (Get $get) => CategoryService::query()
                                                ->where('category_id', (int) $get('category_id'))
                                                ->where('is_active', true)
                                                ->orderBy('sort_order')
                                                ->orderBy('name')
                                                ->pluck('name', 'id'))
                                            ->multiple()
                                            ->searchable()
                                            ->preload()
                                            ->helperText('Можна обрати декілька напрямів для одного профілю.')
                                            ->visible(fn (Get $get) => filled($get('category_id'))),
                                        Placeholder::make('ai_summary')
                                            ->label('AI-аналіз відгуків')
                                            ->content(fn ($record) => new HtmlString(self::renderAiSummary($record)))
                                            ->columnSpanFull(),
                                        RichEditor::make('description')
                                            ->label('Повний опис')
                                            ->columnSpanFull(),
                                        Textarea::make('dossier')
                                            ->label('AI-досьє (markdown)')
                                            ->helperText('Показується на сторінці профілю замість «Коротко про спеціаліста». Підтримує **жирний**, заголовки і списки markdown. Порожнє — блок прихований. Після ручного редагування джерело стає «admin».')
                                            ->rows(14)
                                            ->columnSpanFull(),
                                        Select::make('dossier_verdict')
                                            ->label('Колір верхньої плашки досьє')
                                            ->options([
                                                'green' => 'Зелений — записи чисті',
                                                'yellow' => 'Жовтий — є що перевірити',
                                                'red' => 'Червоний — є критичні факти',
                                            ])
                                            ->placeholder('Без плашки')
                                            ->native(false),
                                        Textarea::make('dossier_verdict_note')
                                            ->label('Висновок у верхній плашці')
                                            ->helperText('Короткий текст поруч із кольоровим висновком у блоці досьє. Редагується окремо від markdown-досьє.')
                                            ->rows(3)
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2),

                                Section::make('Медійні дані')
                                    ->schema([
                                        FileUpload::make('logo_url')
                                            ->label('Логотип / аватар')
                                            ->image()
                                            // Явно public: без цього на хостингу файл ішов на інший
                                            // диск і /media/... його не знаходив (лого «зникало»).
                                            ->disk('public')
                                            ->visibility('public')
                                            ->directory('profiles/logos'),
                                    ]),
                            ]),

                        Tab::make('Позиція компанії')
                            ->badge(fn ($record): ?string => ($record?->owner_statement_status ?? null) === \App\Models\Profile::OWNER_STATEMENT_PENDING ? 'на перевірці' : null)
                            ->badgeColor('warning')
                            ->schema([
                                Section::make('Публічна відповідь власника на досьє')
                                    ->description('Текст пише власник у кабінеті. Публікується на сторінці профілю під досьє лише після схвалення.')
                                    ->schema([
                                        Placeholder::make('owner_statement_submitted_info')
                                            ->label('Надіслано')
                                            ->content(fn ($record): string => $record?->owner_statement_submitted_at
                                                ? $record->owner_statement_submitted_at->translatedFormat('d F Y, H:i')
                                                : 'Власник ще не надсилав позицію')
                                            ->columnSpanFull(),
                                        Textarea::make('owner_statement')
                                            ->label('Текст позиції')
                                            ->rows(8)
                                            ->autosize()
                                            ->maxLength(1500)
                                            ->helperText('Можна відредагувати перед публікацією: прибрати рекламу чи випади, не змінюючи суті.')
                                            ->columnSpanFull(),
                                        Select::make('owner_statement_status')
                                            ->label('Статус')
                                            ->options([
                                                \App\Models\Profile::OWNER_STATEMENT_PENDING => 'На перевірці',
                                                \App\Models\Profile::OWNER_STATEMENT_APPROVED => 'Опубліковано',
                                                \App\Models\Profile::OWNER_STATEMENT_REJECTED => 'Відхилено',
                                            ])
                                            ->native(false)
                                            ->placeholder('Немає позиції'),
                                        TextInput::make('owner_statement_note')
                                            ->label('Причина відхилення (побачить власник)')
                                            ->maxLength(500),
                                    ])
                                    ->columns(['default' => 1, 'md' => 2]),
                            ]),

                        Tab::make('Кейси')
                            ->schema([
                                Section::make('Кейси профілю')
                                    ->description('Показуються на публічній сторінці профілів з активною PRO-підпискою, максимум 10.')
                                    ->schema([
                                        // Підказка зі зразком — перше, що бачить людина у вкладці.
                                        View::make('filament.resources.profiles.cases-hint')
                                            ->columnSpanFull(),
                                        Repeater::make('owner_cases')
                                            // hiddenLabel: інакше Filament малює службове «Owner cases».
                                            ->hiddenLabel()
                                            ->addActionLabel('Додати кейс')
                                            ->maxItems(\App\Support\ProfileCases::MAX_ITEMS)
                                            ->defaultItems(0)
                                            // Кейси часто заповнюють із телефона, тож усе рахується на дотик:
                                            // згорнуті картки замість довжелезної стрічки полів, стрілки
                                            // замість перетягування (drag на тачскріні не працює нормально)
                                            // і одна колонка до планшета.
                                            ->collapsed()
                                            ->collapsible()
                                            // Стрілки замість перетягування: drag-handle на тачскріні
                                            // конфліктує зі скролом сторінки, а кожна зайва кнопка
                                            // з'їдає ширину заголовка картки.
                                            ->reorderableWithButtons()
                                            ->reorderableWithDragAndDrop(false)
                                            ->itemLabel(fn (array $state): string => trim((string) ($state['title'] ?? '')) !== ''
                                                ? \Illuminate\Support\Str::limit((string) $state['title'], 48)
                                                : 'Новий кейс')
                                            ->schema([
                                                TextInput::make('title')
                                                    ->label('Назва кейсу')
                                                    ->maxLength(120)
                                                    ->required()
                                                    // live(onBlur): щойно назву введено, згорнута картка
                                                    // одразу підписується нею, а не «Новим кейсом».
                                                    ->live(onBlur: true)
                                                    ->columnSpanFull(),
                                                Textarea::make('task')
                                                    ->label('З чим прийшов клієнт')
                                                    ->rows(2)
                                                    ->autosize()
                                                    ->maxLength(400)
                                                    ->columnSpanFull(),
                                                Textarea::make('result')
                                                    ->label('Результат')
                                                    ->rows(2)
                                                    ->autosize()
                                                    ->maxLength(400)
                                                    ->required()
                                                    ->columnSpanFull(),
                                                TextInput::make('tag')
                                                    ->label('Напрям')
                                                    ->maxLength(60),
                                                TextInput::make('period')
                                                    ->label('Скільки тривало')
                                                    ->maxLength(40),
                                                TextInput::make('video')
                                                    ->label('Відео')
                                                    ->placeholder('https://youtu.be/… або https://сайт/відео.mp4')
                                                    ->helperText('YouTube, Vimeo або пряме посилання на файл. Інші сервіси покажемо посиланням.')
                                                    ->url()
                                                    ->inputMode('url')
                                                    ->maxLength(300)
                                                    ->columnSpanFull(),
                                                TextInput::make('url')
                                                    ->label('Посилання на деталі')
                                                    ->url()
                                                    ->inputMode('url')
                                                    ->maxLength(300)
                                                    ->columnSpanFull(),
                                                FileUpload::make('image')
                                                    ->label('Фото або заставка відео')
                                                    ->helperText('З телефона можна зняти камерою. Або вставте посилання в полі нижче.')
                                                    ->image()
                                                    ->imagePreviewHeight('120')
                                                    ->maxSize(8192)
                                                    ->disk('public')
                                                    ->visibility('public')
                                                    ->directory('profiles/cases')
                                                    ->columnSpanFull(),
                                                TextInput::make('image_url')
                                                    ->label('…або посилання на фото')
                                                    ->url()
                                                    ->inputMode('url')
                                                    ->maxLength(300)
                                                    ->columnSpanFull(),
                                                // Підтвердження: обираємо реальний опублікований
                                                // відгук цього профілю — його текст відвідувач
                                                // розгортає прямо в картці кейсу.
                                                Select::make('review_id')
                                                    ->label('Підтвердити відгуком клієнта')
                                                    ->placeholder('Без підтвердження')
                                                    ->searchable()
                                                    ->options(fn ($record): array => $record
                                                        ? \App\Models\ProfileReview::query()
                                                            ->where('profile_id', $record->id)
                                                            ->where('status', 'published')
                                                            ->orderByDesc('published_at')
                                                            ->orderByDesc('id')
                                                            ->limit(300)
                                                            ->get(['id', 'author_name', 'rating', 'body', 'published_at'])
                                                            ->mapWithKeys(fn ($review): array => [
                                                                $review->id => sprintf(
                                                                    '%s · %s★ · %s',
                                                                    Str::limit(trim((string) $review->author_name) ?: 'Клієнт', 24),
                                                                    rtrim(rtrim(number_format((float) $review->rating, 1, '.', ''), '0'), '.'),
                                                                    Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $review->body) ?? ''), 70)
                                                                ),
                                                            ])
                                                            ->all()
                                                        : [])
                                                    ->columnSpanFull(),
                                            ])
                                            // Одна колонка на телефоні: два поля поруч на 390px
                                            // перетворюються на нечитабельні смужки.
                                            ->columns(['default' => 1, 'md' => 2])
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                        Tab::make('Контакти')
                            ->schema([
                                Section::make('Контакти')
                                    ->schema([
                                        TextInput::make('website')->label('Сайт')->url(),
                                        TextInput::make('contact_cta_url')
                                            ->label('Посилання для кнопки "Зв\'язатись"')
                                            ->url()
                                            ->helperText('Якщо поле порожнє, кнопка використовує посилання із поля "Сайт".'),
                                        TextInput::make('email')->label('Email')->email(),
                                        // Без ->tel(): його regex не пропускає кілька номерів через
                                        // кому. Фронт (mapProfilePhoneLinks) сам розбиває список по
                                        // комі/«;»/«/» і рендерить окремі tel:-посилання.
                                        TextInput::make('phone')
                                            ->label('Телефон')
                                            ->maxLength(255)
                                            ->placeholder('+380 67 123 45 67, +380 50 765 43 21')
                                            ->helperText('Можна кілька номерів — через кому.'),
                                        TextInput::make('address')->label('Адреса'),
                                        Select::make('city')
                                            ->label('Місто')
                                            ->options(fn () => RegionCityDirectory::registeredCityOptions())
                                            ->searchable()
                                            ->preload()
                                            ->native(false)
                                            ->helperText('Тільки міста з реєстру. Старі варіанти назв нормалізуються під час аудиту.'),
                                        Select::make('region_id')
                                            ->label('Регіон')
                                            ->relationship('region', 'name')
                                            ->searchable()
                                            ->preload(),
                                        Repeater::make('social_links')
                                            ->label('Соцмережі')
                                            ->schema([
                                                Select::make('network')
                                                    ->label('Платформа')
                                                    ->options([
                                                        'instagram' => 'Instagram',
                                                        'telegram' => 'Telegram',
                                                        'facebook' => 'Facebook',
                                                        'tiktok' => 'TikTok',
                                                        'youtube' => 'YouTube',
                                                        'linkedin' => 'LinkedIn',
                                                        'x' => 'X (Twitter)',
                                                        'viber' => 'Viber',
                                                        'whatsapp' => 'WhatsApp',
                                                        'website' => 'Website',
                                                        'other' => 'Інше',
                                                    ])
                                                    ->searchable()
                                                    ->preload()
                                                    ->required(),
                                                TextInput::make('url')
                                                    ->label('URL')
                                                    ->url()
                                                    ->required()
                                                    ->maxLength(500),
                                            ])
                                            ->columns(2)
                                            ->defaultItems(0)
                                            ->collapsed()
                                            ->addActionLabel('Додати соцмережу')
                                            ->reorderable(false)
                                            ->mutateDehydratedStateUsing(function (?array $state): array {
                                                $mapped = [];

                                                foreach ($state ?? [] as $item) {
                                                    $network = trim((string) ($item['network'] ?? ''));
                                                    $url = trim((string) ($item['url'] ?? ''));

                                                    if ($network === '' || $url === '') {
                                                        continue;
                                                    }

                                                    $mapped[$network] = $url;
                                                }

                                                return $mapped;
                                            })
                                            ->afterStateHydrated(function (Repeater $component, mixed $state): void {
                                                if (! is_array($state) || $state === []) {
                                                    return;
                                                }

                                                if (array_is_list($state)) {
                                                    return;
                                                }

                                                $normalized = [];

                                                foreach ($state as $network => $url) {
                                                    if (is_array($url)) {
                                                        $normalized[] = [
                                                            'network' => (string) ($url['network'] ?? $network),
                                                            'url' => (string) ($url['url'] ?? ''),
                                                        ];
                                                    } else {
                                                        $normalized[] = [
                                                            'network' => (string) $network,
                                                            'url' => (string) $url,
                                                        ];
                                                    }
                                                }

                                                $component->state($normalized);
                                            })
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(3),
                            ]),

                        Tab::make('Статуси та SEO')
                            ->schema([
                                Section::make('Статуси та SEO')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                Section::make('Статуси')
                                                    ->compact()
                                                    ->schema([
                                                        Toggle::make('is_verified')->label('Verified'),
                                                        Select::make('dovira_recommendation_status')
                                                            ->label('Рішення Dovira')
                                                            ->options([
                                                                'recommend' => 'Dovira рекомендує',
                                                                'not_recommend' => 'Dovira не рекомендує',
                                                            ])
                                                            ->placeholder('Не вказано')
                                                            ->native(false),
                                                        Toggle::make('is_owner_verified')->label('Права власника підтверджені'),
                                                        Toggle::make('is_pro')->label('PRO'),
                                                        TextInput::make('pro_discount_percent')
                                                            ->label('PRO знижка, %')
                                                            ->numeric()
                                                            ->minValue(0)
                                                            ->maxValue(90)
                                                            ->step(1)
                                                            // Колонка NOT NULL DEFAULT 0 — порожнє поле не має слати null
                                                            // (інакше падає створення профілю).
                                                            ->default(0)
                                                            ->dehydrateStateUsing(fn ($state) => (int) ($state ?? 0))
                                                            ->helperText('Наприклад 20 = рахунок monopay буде зі знижкою −20%.'),
                                                        Toggle::make('is_featured')->label('Featured'),
                                                        Placeholder::make('owner_verified_status')
                                                            ->label('Права власника')
                                                            ->content(function ($record): string {
                                                                if (! $record) {
                                                                    return '—';
                                                                }

                                                                $isOwnerVerified = isset($record->has_approved_claim)
                                                                    ? (bool) $record->has_approved_claim
                                                                    : $record->claims()->where('status', 'approved')->exists();

                                                                return $isOwnerVerified
                                                                    ? 'Підтверджено власником / офіційним представником'
                                                                    : 'Немає підтвердженого володіння профілем';
                                                            })
                                                            ->helperText('Це означає, що користувач довів право керувати профілем або був підтверджений адміном.'),
                                                        Select::make('status')
                                                            ->label('Статус видимості')
                                                            ->options([
                                                                'active' => 'Опубліковано',
                                                                'hidden' => 'Приховано',
                                                                'draft' => 'Чернетка',
                                                                'archived' => 'Архів',
                                                            ])
                                                            ->default('active')
                                                            ->required(),
                                                        Toggle::make('is_published')->label('Published'),
                                                        Toggle::make('show_in_catalog')->label('Показувати в каталозі')->default(true),
                                                        TextInput::make('sort_priority')
                                                            ->label('Пріоритет сортування')
                                                            ->numeric()
                                                            ->default(0),
                                                        Select::make('rating_placement')
                                                            ->label('Показ у рейтингах')
                                                            ->options([
                                                                'auto' => 'Автоматично',
                                                                'force' => 'Поставити вручну',
                                                                'hide' => 'Сховати з рейтингів',
                                                            ])
                                                            ->default('auto')
                                                            ->native(false)
                                                            ->helperText('Впливає на блок “Хто найкращий” і сторінки “Найкращі ...”.'),
                                                        TextInput::make('rating_priority')
                                                            ->label('Пріоритет у рейтингах')
                                                            ->numeric()
                                                            ->default(0)
                                                            ->helperText('Більше число = вище серед ручно виставлених профілів.'),
                                                    ]),
                                                Section::make('SEO')
                                                    ->compact()
                                                    ->schema([
                                                        TextInput::make('seo_title')->label('SEO title'),
                                                        Textarea::make('seo_description')->label('SEO description')->rows(3),
                                                        FileUpload::make('og_image_url')
                                                            ->label('OG image')
                                                            ->image()
                                                            ->disk('public')
                                                            ->visibility('public')
                                                            ->directory('profiles/og'),
                                                    ]),
                                            ]),
                                    ]),

                                Section::make('Системна інформація')
                                    ->schema([
                                        Select::make('owner_user_id')
                                            ->label('Пов’язаний користувач / власник профілю')
                                            ->relationship('owner', 'email')
                                            ->searchable()
                                            ->preload(),
                                        Placeholder::make('created_at')
                                            ->label('Дата створення')
                                            ->content(fn ($record) => $record?->created_at?->format('d.m.Y H:i') ?? '—'),
                                        Placeholder::make('created_by_user_id')
                                            ->label('Хто створив')
                                            ->content(fn ($record) => $record?->creator?->email ?? '—'),
                                        Placeholder::make('updated_by_user_id')
                                            ->label('Хто редагував')
                                            ->content(fn ($record) => $record?->updater?->email ?? '—'),
                                        Textarea::make('internal_note')
                                            ->label('Внутрішня нотатка')
                                            ->rows(3)
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2),
                            ]),

                        Tab::make('AI-збагачення')
                            ->schema([
                                View::make('filament.resources.profiles.tabs.ai-enrichment')
                                    ->viewData(fn ($record) => ['record' => $record])
                                    ->columnSpanFull(),
                            ]),

                        Tab::make('Аналітика')
                            ->schema([
                                View::make('filament.resources.profiles.tabs.analytics')
                                    ->viewData(fn ($record) => ['record' => $record])
                                    ->columnSpanFull(),
                            ]),

                        Tab::make('Відгуки')
                            ->schema([
                                View::make('filament.resources.profiles.tabs.reviews')
                                    ->viewData(fn ($record) => ['record' => $record])
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->columnSpanFull(),
            ])
            ->columns(1);
    }

    private static function renderAiSummary($record): string
    {
        $rating = $record?->rating_avg ? number_format((float) $record->rating_avg, 1) : '0.0';
        $reviews = (int) ($record?->reviews_count ?? 0);
        $summary = trim((string) data_get($record?->ai_suggested_data ?? [], 'review_analysis.summary', ''));
        $factors = array_values(array_filter((array) data_get($record?->ai_suggested_data ?? [], 'review_analysis.key_factors', [])));

        if ($summary === '') {
            $summary = 'AI-підсумок ще не сформовано. Після обробки відгуків тут зʼявляться сильні сторони, ризики та патерни комунікації.';
        }

        $factorsHtml = '';
        if ($factors !== []) {
            $items = implode('', array_map(
                fn ($item) => '<li style="margin:.2rem 0;">' . e((string) $item) . '</li>',
                $factors
            ));
            $factorsHtml = '<ul style="margin:.5rem 0 0;padding-left:1.1rem;">' . $items . '</ul>';
        }

        return '
            <div class="profile-summary-ai" style="margin:0;">
                <div class="profile-summary-ai__head">
                    <i class="fa-solid fa-brain" aria-hidden="true"></i>
                    <span>AI-аналіз відгуків</span>
                </div>
                <p class="profile-summary-ai__text">
                    ' . nl2br(e($summary)) . '
                </p>
                ' . $factorsHtml . '
                <p class="profile-summary-ai__meta" style="margin-top:.5rem;opacity:.72;font-size:.82rem;">
                    Оцінка: ' . $rating . ' · Відгуків: ' . $reviews . '
                </p>
            </div>
        ';
    }
}
