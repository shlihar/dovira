@if (! isset($record) || ! $record?->id)
    <x-filament::section>
        <x-slot name="heading">AI-збагачення</x-slot>
        <div class="text-sm opacity-70">AI-дані будуть доступні після створення профілю.</div>
    </x-filament::section>
@else
    @php
        $latestTask = $record->aiEnrichmentTasks()->latest()->first();
        $sources = $record->dataSources()->latest()->limit(12)->get();
        $mentions = $record->externalMentions()->latest()->limit(12)->get();
        $reviewDrafts = $record->reviews()
            ->where('verification_type', 'external_ai_draft')
            ->latest()
            ->limit(8)
            ->get();
        $suggested = is_array($record->ai_suggested_data) ? $record->ai_suggested_data : [];
        $confidence = (int) ($record->ai_confidence_score ?? $latestTask?->confidence_score ?? 0);
        $confidenceTone = $confidence >= 80 ? 'green' : ($confidence >= 50 ? 'amber' : 'red');
        $status = $record->ai_enrichment_status ?? $latestTask?->status ?? 'not_enriched';

        $toText = function ($value, string $fallback = '—'): string {
            if ($value instanceof \BackedEnum) {
                return (string) $value->value;
            }

            if ($value instanceof \Stringable) {
                $value = (string) $value;
            }

            if ($value === null) {
                return $fallback;
            }

            if (is_bool($value)) {
                return $value ? 'Так' : 'Ні';
            }

            if (is_scalar($value)) {
                $text = trim((string) $value);

                return $text !== '' ? $text : $fallback;
            }

            if (is_array($value)) {
                $flat = collect($value)
                    ->flatten()
                    ->filter(fn ($item) => is_scalar($item) && filled((string) $item))
                    ->map(fn ($item) => trim((string) $item))
                    ->values();

                return $flat->isNotEmpty() ? $flat->implode(', ') : $fallback;
            }

            return $fallback;
        };

        $toUrl = function ($value) use ($toText): string {
            $url = $toText($value, '');

            return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
        };

        $normalizeFoundFields = function ($fields): array {
            if (! is_array($fields)) {
                return [];
            }

            if (array_is_list($fields)) {
                return array_values(array_filter($fields, fn ($value) => filled($value)));
            }

            return array_keys(array_filter($fields));
        };

        $mainFields = collect([
            'name' => 'Назва',
            'category' => 'Категорія',
            'city' => 'Місто',
            'address' => 'Адреса',
            'phone' => 'Телефон',
            'website' => 'Сайт',
            'email' => 'Email',
            'seo_title' => 'SEO title',
            'seo_description' => 'SEO description',
        ])->filter(fn ($label, $key) => filled($toText(data_get($suggested, $key), '')));

        $services = collect(data_get($suggested, 'services', []))
            ->flatten()
            ->map(fn ($value) => $toText($value, ''))
            ->filter()
            ->values();
        $socialLinks = collect(data_get($suggested, 'social_links', []))
            ->mapWithKeys(function ($url, $network) use ($toText, $toUrl) {
                $url = $toUrl($url);

                return $url !== '' ? [$toText($network, 'Соцмережа') => $url] : [];
            });
    @endphp

    <style>
        .dovira-profile-ai {
            display: grid;
            gap: 1rem;
        }

        .dovira-profile-ai * {
            box-sizing: border-box;
        }

        .dovira-profile-ai__summary,
        .dovira-profile-ai__grid,
        .dovira-profile-ai__fields {
            display: grid;
            gap: .75rem;
        }

        .dovira-profile-ai__summary {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .dovira-profile-ai__grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .dovira-profile-ai__fields {
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        }

        .dovira-profile-ai__card,
        .dovira-profile-ai__box,
        .dovira-profile-ai__source,
        .dovira-profile-ai__mention,
        .dovira-profile-ai__review {
            min-width: 0;
            border: 1px solid rgba(148, 163, 184, .26);
            border-radius: 1rem;
            background: rgba(255, 255, 255, .72);
        }

        .dark .dovira-profile-ai__card,
        .dark .dovira-profile-ai__box,
        .dark .dovira-profile-ai__source,
        .dark .dovira-profile-ai__mention,
        .dark .dovira-profile-ai__review {
            border-color: rgba(255, 255, 255, .11);
            background: rgba(255, 255, 255, .035);
        }

        .dovira-profile-ai__card,
        .dovira-profile-ai__box,
        .dovira-profile-ai__source,
        .dovira-profile-ai__mention,
        .dovira-profile-ai__review {
            padding: 1rem;
        }

        .dovira-profile-ai__label {
            color: #64748b;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .08em;
            line-height: 1.25;
            text-transform: uppercase;
        }

        .dark .dovira-profile-ai__label {
            color: rgba(255, 255, 255, .5);
        }

        .dovira-profile-ai__value {
            min-width: 0;
            margin-top: .4rem;
            color: #0f172a;
            font-size: .95rem;
            font-weight: 700;
            line-height: 1.35;
            overflow-wrap: anywhere;
        }

        .dark .dovira-profile-ai__value {
            color: rgba(255, 255, 255, .92);
        }

        .dovira-profile-ai__muted {
            color: #64748b;
            font-size: .84rem;
            line-height: 1.55;
        }

        .dark .dovira-profile-ai__muted {
            color: rgba(255, 255, 255, .58);
        }

        .dovira-profile-ai__text {
            margin-top: .55rem;
            color: #334155;
            font-size: .9rem;
            line-height: 1.65;
        }

        .dark .dovira-profile-ai__text {
            color: rgba(255, 255, 255, .74);
        }

        .dovira-profile-ai__list {
            display: grid;
            gap: .7rem;
        }

        .dovira-profile-ai__head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
        }

        .dovira-profile-ai__title {
            color: #0f172a;
            font-size: .95rem;
            font-weight: 700;
            line-height: 1.35;
        }

        .dark .dovira-profile-ai__title {
            color: rgba(255, 255, 255, .92);
        }

        .dovira-profile-ai__chips {
            display: flex;
            flex-wrap: wrap;
            gap: .45rem;
        }

        .dovira-profile-ai__pill,
        .dovira-profile-ai__chip {
            display: inline-flex;
            align-items: center;
            width: max-content;
            max-width: 100%;
            border-radius: 999px;
            padding: .25rem .58rem;
            color: #334155;
            background: #f1f5f9;
            font-size: .75rem;
            font-weight: 700;
            line-height: 1.25;
        }

        .dark .dovira-profile-ai__pill,
        .dark .dovira-profile-ai__chip {
            color: rgba(255, 255, 255, .82);
            background: rgba(255, 255, 255, .08);
        }

        .dovira-profile-ai__pill--green { color: #15803d; background: rgba(34, 197, 94, .14); }
        .dovira-profile-ai__pill--amber { color: #b45309; background: rgba(245, 158, 11, .16); }
        .dovira-profile-ai__pill--red { color: #b91c1c; background: rgba(239, 68, 68, .14); }
        .dovira-profile-ai__pill--blue { color: #1d4ed8; background: rgba(59, 130, 246, .14); }

        .dovira-profile-ai__url {
            display: inline-block;
            max-width: 100%;
            margin-top: .45rem;
            color: #2563eb;
            font-size: .82rem;
            line-height: 1.4;
            overflow-wrap: anywhere;
            text-decoration: none;
        }

        .dovira-profile-ai__url:hover {
            text-decoration: underline;
        }

        @media (max-width: 900px) {
            .dovira-profile-ai__summary,
            .dovira-profile-ai__grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="dovira-profile-ai">
        <x-filament::section>
            <x-slot name="heading">AI-збагачення</x-slot>
            <x-slot name="description">Дані нижче є пропозиціями для ручної перевірки. Профіль, зовнішні згадки й відгуки не публікуються автоматично.</x-slot>

            <div class="dovira-profile-ai__summary">
                <div class="dovira-profile-ai__card">
                    <div class="dovira-profile-ai__label">Статус</div>
                    <div class="dovira-profile-ai__value">
                        <span class="dovira-profile-ai__pill dovira-profile-ai__pill--blue">{{ str($status)->replace('_', ' ')->title() }}</span>
                    </div>
                </div>

                <div class="dovira-profile-ai__card">
                    <div class="dovira-profile-ai__label">Confidence score</div>
                    <div class="dovira-profile-ai__value">
                        <span class="dovira-profile-ai__pill dovira-profile-ai__pill--{{ $confidenceTone }}">{{ $confidence }}%</span>
                    </div>
                    <div class="dovira-profile-ai__muted">{{ $confidence >= 80 ? 'Дані якісні' : ($confidence >= 50 ? 'Потрібна перевірка' : 'Слабкі або неповні дані') }}</div>
                </div>

                <div class="dovira-profile-ai__card">
                    <div class="dovira-profile-ai__label">Оновлено AI</div>
                    <div class="dovira-profile-ai__value">{{ $record->ai_enriched_at?->format('d.m.Y H:i') ?? $latestTask?->processed_at?->format('d.m.Y H:i') ?? '—' }}</div>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Запропоновані дані</x-slot>
            <x-slot name="description">Структурована інформація, яку можна прийняти або відредагувати вручну.</x-slot>

            @if ($suggested === [])
                <div class="dovira-profile-ai__muted">Поки немає запропонованих AI-даних.</div>
            @else
                <div class="dovira-profile-ai__grid">
                    <div class="dovira-profile-ai__box">
                        <div class="dovira-profile-ai__label">Опис профілю</div>
                        <div class="dovira-profile-ai__text">{{ $toText(data_get($suggested, 'description'), 'Опис ще не згенеровано.') }}</div>
                    </div>

                    <div class="dovira-profile-ai__box">
                        <div class="dovira-profile-ai__label">Короткий опис</div>
                        <div class="dovira-profile-ai__text">{{ $toText(data_get($suggested, 'short_description'), 'Короткий опис ще не згенеровано.') }}</div>
                    </div>
                </div>

                @if ($mainFields->isNotEmpty())
                    <div class="dovira-profile-ai__fields">
                        @foreach ($mainFields as $key => $label)
                            <div class="dovira-profile-ai__box">
                                <div class="dovira-profile-ai__label">{{ $label }}</div>
                                <div class="dovira-profile-ai__value">{{ $toText(data_get($suggested, $key)) }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($services->isNotEmpty())
                    <div class="dovira-profile-ai__box">
                        <div class="dovira-profile-ai__label">Напрями / послуги</div>
                        <div class="dovira-profile-ai__chips" style="margin-top:.6rem;">
                            @foreach ($services as $service)
                                <span class="dovira-profile-ai__chip">{{ $service }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($socialLinks->isNotEmpty())
                    <div class="dovira-profile-ai__box">
                        <div class="dovira-profile-ai__label">Соцмережі</div>
                        <div class="dovira-profile-ai__list" style="margin-top:.65rem;">
                            @foreach ($socialLinks as $network => $url)
                                <div>
                                    <span class="dovira-profile-ai__pill">{{ $network }}</span>
                                    <a class="dovira-profile-ai__url" href="{{ $url }}" target="_blank" rel="noopener noreferrer">{{ $url }}</a>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Джерела</x-slot>
            <x-slot name="description">Фактичні дані потрібно перевірити за джерелами перед публікацією.</x-slot>

            <div class="dovira-profile-ai__list">
                @forelse ($sources as $source)
                    @php
                        $foundFields = $normalizeFoundFields($source->found_fields);
                        $sourceUrl = $toUrl($source->url);
                    @endphp
                    <div class="dovira-profile-ai__source">
                        <div class="dovira-profile-ai__head">
                            <div>
                                <div class="dovira-profile-ai__title">{{ $toText($source->title, $toText($source->source_type, 'Джерело')) }}</div>
                                <div class="dovira-profile-ai__muted">{{ $toText($source->source_type, 'source') }}</div>
                            </div>
                            <span class="dovira-profile-ai__pill dovira-profile-ai__pill--blue">{{ $source->confidence_score ?? 0 }}%</span>
                        </div>

                        @if ($sourceUrl !== '')
                            <a class="dovira-profile-ai__url" href="{{ $sourceUrl }}" target="_blank" rel="noopener noreferrer">{{ $sourceUrl }}</a>
                        @else
                            <div class="dovira-profile-ai__muted">Без URL</div>
                        @endif

                        @if ($foundFields !== [])
                            <div class="dovira-profile-ai__chips" style="margin-top:.65rem;">
                                @foreach ($foundFields as $field)
                                    <span class="dovira-profile-ai__chip">{{ $toText($field) }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="dovira-profile-ai__muted">Джерел поки немає.</div>
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Зовнішні згадки</x-slot>
            <x-slot name="description">Знайдені публічні згадки та зовнішні відгуки. Це ще не опубліковані відгуки DOVIRA.</x-slot>

            <div class="dovira-profile-ai__list">
                @forelse ($mentions as $mention)
                    @php
                        $payload = is_array($mention->raw_payload) ? $mention->raw_payload : [];
                        $mentionUrl = $toUrl($mention->url);
                        $reviewText = $toText(data_get($payload, 'review_text') ?: data_get($payload, 'text') ?: data_get($payload, 'snippet'), '');
                        $reviewAuthor = $toText(data_get($payload, 'review_author') ?: data_get($payload, 'author_name'), '');
                        $isReviewCandidate = $mentionUrl !== '' && $reviewText !== '';
                    @endphp
                    <div class="dovira-profile-ai__mention">
                        <div class="dovira-profile-ai__head">
                            <div>
                                <div class="dovira-profile-ai__title">{{ $toText($mention->title, 'Зовнішня згадка') }}</div>
                                <div class="dovira-profile-ai__muted">
                                    @if ($reviewAuthor !== '')
                                        {{ $reviewAuthor }} ·
                                    @endif
                                    {{ $toText($mention->sentiment, 'unknown') }}
                                    @if ($mention->external_rating)
                                        · {{ $mention->external_rating }} external
                                    @endif
                                </div>
                            </div>
                            <div class="dovira-profile-ai__chips">
                                @if ($isReviewCandidate)
                                    <span class="dovira-profile-ai__pill dovira-profile-ai__pill--green">Кандидат у відгук</span>
                                @endif
                                <span class="dovira-profile-ai__pill dovira-profile-ai__pill--blue">{{ $mention->confidence_score ?? 0 }}%</span>
                            </div>
                        </div>

                        @if ($mentionUrl !== '')
                            <a class="dovira-profile-ai__url" href="{{ $mentionUrl }}" target="_blank" rel="noopener noreferrer">{{ $mentionUrl }}</a>
                        @endif

                        @if (filled($toText($mention->summary, '')))
                            <div class="dovira-profile-ai__text">{{ $toText($mention->summary, '') }}</div>
                        @endif

                        @if ($reviewText !== '')
                            <div class="dovira-profile-ai__text">{{ $reviewText }}</div>
                        @endif
                    </div>
                @empty
                    <div class="dovira-profile-ai__muted">Зовнішніх згадок поки немає.</div>
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Чернетки відгуків DOVIRA</x-slot>
            <x-slot name="description">Source-backed кандидати, створені AI. Вони мають статус на модерації і не впливають на рейтинг до публікації.</x-slot>

            <div class="dovira-profile-ai__list">
                @forelse ($reviewDrafts as $review)
                    <div class="dovira-profile-ai__review">
                        <div class="dovira-profile-ai__head">
                            <div>
                                <div class="dovira-profile-ai__title">{{ $toText($review->author_name ?: $review->external_review_author ?: $review->user?->name, 'Автор не вказаний') }}</div>
                                <div class="dovira-profile-ai__muted">{{ number_format((float) $review->rating, 1) }} / 5 · {{ $review->created_at?->format('d.m.Y H:i') }}</div>
                            </div>
                            <span class="dovira-profile-ai__pill dovira-profile-ai__pill--amber">{{ $review->status }}</span>
                        </div>

                        <div class="dovira-profile-ai__text">{{ $toText($review->body) }}</div>

                        @php
                            $reviewSourceUrl = $toUrl($review->external_source_url);
                        @endphp
                        @if ($reviewSourceUrl !== '')
                            <a class="dovira-profile-ai__url" href="{{ $reviewSourceUrl }}" target="_blank" rel="noopener noreferrer">{{ $reviewSourceUrl }}</a>
                        @endif
                    </div>
                @empty
                    <div class="dovira-profile-ai__muted">Чернеток зовнішніх відгуків поки немає.</div>
                @endforelse
            </div>
        </x-filament::section>
    </div>
@endif
