@php
    $suggested = is_array($task->suggested_data ?? null) ? $task->suggested_data : [];
    $rawPayload = is_array($task->raw_payload ?? null) ? $task->raw_payload : [];
    $duplicates = is_array($task->duplicate_candidates ?? null) ? $task->duplicate_candidates : [];
    $reviewDrafts = $task->profile
        ? $task->profile->reviews()
            ->where('verification_type', 'external_ai_draft')
            ->latest()
            ->limit(8)
            ->get()
        : collect();

    $statusLabels = [
        'pending' => 'Очікує',
        'processing' => 'В процесі',
        'data_found' => 'Дані знайдено',
        'needs_review' => 'Потрібна перевірка',
        'possible_duplicate' => 'Можливий дублікат',
        'completed' => 'Завершено',
        'error' => 'Помилка',
        'rejected' => 'Відхилено',
    ];

    $statusTones = [
        'pending' => 'blue',
        'processing' => 'cyan',
        'data_found' => 'green',
        'needs_review' => 'amber',
        'possible_duplicate' => 'amber',
        'completed' => 'green',
        'error' => 'red',
        'rejected' => 'red',
    ];

    $fieldLabels = [
        'name' => 'Назва',
        'category' => 'Категорія',
        'city' => 'Місто',
        'country' => 'Країна',
        'address' => 'Адреса',
        'phone' => 'Телефон',
        'website' => 'Сайт',
        'email' => 'Email',
        'seo_title' => 'SEO title',
        'seo_description' => 'SEO description',
    ];

    $mainFields = collect($fieldLabels)
        ->filter(fn ($label, $key) => filled(data_get($suggested, $key)));

    $socialLinks = collect(data_get($suggested, 'social_links', []))
        ->filter(fn ($value) => filled($value));

    $services = collect(data_get($suggested, 'services', []))
        ->filter(fn ($value) => filled($value));

    $confidence = (int) ($task->confidence_score ?? data_get($suggested, 'confidence_score', 0));
    $confidenceTone = $confidence >= 80 ? 'green' : ($confidence >= 50 ? 'amber' : 'red');

    $normalizeFoundFields = function ($fields): array {
        if (! is_array($fields)) {
            return [];
        }

        if (array_is_list($fields)) {
            return array_values(array_filter($fields, fn ($value) => filled($value)));
        }

        return array_keys(array_filter($fields));
    };

    $toText = function ($value, string $fallback = '—'): string {
        if (is_array($value)) {
            $value = collect($value)
                ->flatten()
                ->filter(fn ($item) => filled($item) && ! is_array($item))
                ->map(fn ($item) => (string) $item)
                ->implode(', ');
        }

        if (is_bool($value)) {
            $value = $value ? 'Так' : 'Ні';
        }

        return filled($value) ? (string) $value : $fallback;
    };

    $toUrl = function ($value) use ($toText): string {
        $url = trim($toText($value, ''));

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    };
@endphp

<style>
    .dovira-ai-task {
        display: grid;
        gap: 1rem;
    }

    .dovira-ai-task * {
        box-sizing: border-box;
    }

    .dovira-ai-task__summary {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: .75rem;
    }

    .dovira-ai-card,
    .dovira-ai-source,
    .dovira-ai-mention,
    .dovira-ai-review,
    .dovira-ai-textbox {
        border: 1px solid rgba(148, 163, 184, .26);
        border-radius: 1rem;
        background: rgba(255, 255, 255, .72);
    }

    .dark .dovira-ai-card,
    .dark .dovira-ai-source,
    .dark .dovira-ai-mention,
    .dark .dovira-ai-review,
    .dark .dovira-ai-textbox {
        border-color: rgba(255, 255, 255, .11);
        background: rgba(255, 255, 255, .035);
    }

    .dovira-ai-card {
        min-width: 0;
        padding: 1rem;
    }

    .dovira-ai-label {
        color: #64748b;
        font-size: .72rem;
        font-weight: 700;
        letter-spacing: .08em;
        line-height: 1.25;
        text-transform: uppercase;
    }

    .dark .dovira-ai-label {
        color: rgba(255, 255, 255, .48);
    }

    .dovira-ai-value {
        min-width: 0;
        margin-top: .4rem;
        color: #0f172a;
        font-size: .95rem;
        font-weight: 650;
        line-height: 1.35;
        overflow-wrap: anywhere;
    }

    .dark .dovira-ai-value {
        color: rgba(255, 255, 255, .92);
    }

    .dovira-ai-muted {
        color: #64748b;
        font-size: .85rem;
        line-height: 1.45;
    }

    .dark .dovira-ai-muted {
        color: rgba(255, 255, 255, .54);
    }

    .dovira-ai-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: .75rem;
    }

    .dovira-ai-fields {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: .75rem;
    }

    .dovira-ai-field {
        min-width: 0;
        padding: .85rem;
        border: 1px solid rgba(148, 163, 184, .22);
        border-radius: .875rem;
        background: rgba(148, 163, 184, .06);
    }

    .dark .dovira-ai-field {
        border-color: rgba(255, 255, 255, .08);
        background: rgba(255, 255, 255, .025);
    }

    .dovira-ai-textbox {
        padding: 1rem;
    }

    .dovira-ai-textbox__text {
        margin-top: .55rem;
        color: #1e293b;
        font-size: .95rem;
        line-height: 1.65;
        white-space: pre-wrap;
    }

    .dark .dovira-ai-textbox__text {
        color: rgba(255, 255, 255, .78);
    }

    .dovira-ai-chips {
        display: flex;
        flex-wrap: wrap;
        gap: .45rem;
        margin-top: .65rem;
    }

    .dovira-ai-chip,
    .dovira-ai-pill {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        width: max-content;
        max-width: 100%;
        border-radius: 999px;
        font-size: .75rem;
        font-weight: 700;
        line-height: 1;
        white-space: nowrap;
    }

    .dovira-ai-chip {
        padding: .38rem .6rem;
        color: #1d4ed8;
        background: rgba(59, 130, 246, .12);
    }

    .dark .dovira-ai-chip {
        color: #93c5fd;
        background: rgba(59, 130, 246, .16);
    }

    .dovira-ai-pill {
        padding: .32rem .58rem;
        color: #334155;
        background: #f1f5f9;
    }

    .dark .dovira-ai-pill {
        color: rgba(255, 255, 255, .82);
        background: rgba(255, 255, 255, .08);
    }

    .dovira-ai-pill--green { color: #15803d; background: rgba(34, 197, 94, .14); }
    .dovira-ai-pill--cyan { color: #0e7490; background: rgba(6, 182, 212, .14); }
    .dovira-ai-pill--amber { color: #b45309; background: rgba(245, 158, 11, .16); }
    .dovira-ai-pill--red { color: #b91c1c; background: rgba(239, 68, 68, .14); }
    .dovira-ai-pill--blue { color: #1d4ed8; background: rgba(59, 130, 246, .14); }

    .dovira-ai-list {
        display: grid;
        gap: .65rem;
    }

    .dovira-ai-source,
    .dovira-ai-mention {
        display: grid;
        gap: .65rem;
        padding: .9rem;
    }

    .dovira-ai-source__head,
    .dovira-ai-mention__head,
    .dovira-ai-review__head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
    }

    .dovira-ai-source__title,
    .dovira-ai-mention__title,
    .dovira-ai-review__title {
        color: #0f172a;
        font-size: .95rem;
        font-weight: 700;
        line-height: 1.35;
    }

    .dark .dovira-ai-source__title,
    .dark .dovira-ai-mention__title,
    .dark .dovira-ai-review__title {
        color: rgba(255, 255, 255, .92);
    }

    .dovira-ai-review__text {
        color: #334155;
        font-size: .88rem;
        line-height: 1.6;
    }

    .dark .dovira-ai-review__text {
        color: rgba(255, 255, 255, .74);
    }

    .dovira-ai-url {
        color: #64748b;
        display: inline-block;
        font-size: .82rem;
        line-height: 1.35;
        max-width: 100%;
        overflow-wrap: anywhere;
        text-decoration: none;
    }

    .dovira-ai-url:hover {
        color: #2563eb;
        text-decoration: underline;
    }

    .dovira-ai-raw {
        border: 1px solid rgba(148, 163, 184, .24);
        border-radius: .875rem;
        overflow: hidden;
    }

    .dark .dovira-ai-raw {
        border-color: rgba(255, 255, 255, .1);
    }

    .dovira-ai-raw summary {
        cursor: pointer;
        padding: .75rem .9rem;
        color: #334155;
        font-size: .86rem;
        font-weight: 700;
    }

    .dark .dovira-ai-raw summary {
        color: rgba(255, 255, 255, .78);
    }

    .dovira-ai-raw pre {
        max-height: 260px;
        overflow: auto;
        margin: 0;
        padding: .9rem;
        border-top: 1px solid rgba(148, 163, 184, .18);
        color: #475569;
        font-size: .76rem;
        line-height: 1.5;
        white-space: pre-wrap;
    }

    .dark .dovira-ai-raw pre {
        border-top-color: rgba(255, 255, 255, .08);
        color: rgba(255, 255, 255, .68);
    }

    @media (max-width: 900px) {
        .dovira-ai-task__summary,
        .dovira-ai-grid,
        .dovira-ai-fields {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="dovira-ai-task">
    <div class="dovira-ai-task__summary">
        <div class="dovira-ai-card">
            <div class="dovira-ai-label">Профіль</div>
            <div class="dovira-ai-value">{{ $task->profile?->name ?? 'Профіль ще не створено' }}</div>
            <div class="dovira-ai-muted">{{ $task->category?->name ?? 'Категорію не визначено' }}</div>
        </div>

        <div class="dovira-ai-card">
            <div class="dovira-ai-label">Статус</div>
            <div class="dovira-ai-value">
                <span class="dovira-ai-pill dovira-ai-pill--{{ $statusTones[$task->status] ?? 'blue' }}">
                    {{ $statusLabels[$task->status] ?? $task->status }}
                </span>
            </div>
            <div class="dovira-ai-muted">{{ $task->processed_at?->format('d.m.Y H:i') ?? 'Ще не оброблено' }}</div>
        </div>

        <div class="dovira-ai-card">
            <div class="dovira-ai-label">Confidence</div>
            <div class="dovira-ai-value">
                <span class="dovira-ai-pill dovira-ai-pill--{{ $confidenceTone }}">{{ $confidence }}%</span>
            </div>
            <div class="dovira-ai-muted">{{ count($duplicates) }} можливих дублікатів</div>
        </div>
    </div>

    <x-filament::section>
        <x-slot name="heading">AI-запропоновані дані</x-slot>
        <x-slot name="description">Чернетка для ручної перевірки. Публікація не відбувається автоматично.</x-slot>

        @if ($suggested === [])
            <div class="dovira-ai-muted">AI-даних ще немає.</div>
        @else
            <div class="dovira-ai-grid">
                <div class="dovira-ai-textbox">
                    <div class="dovira-ai-label">Продаючий опис</div>
                    <div class="dovira-ai-textbox__text">{{ data_get($suggested, 'description') ?: 'Опис ще не згенеровано.' }}</div>
                </div>

                <div class="dovira-ai-textbox">
                    <div class="dovira-ai-label">Короткий опис</div>
                    <div class="dovira-ai-textbox__text">{{ data_get($suggested, 'short_description') ?: 'Короткий опис ще не згенеровано.' }}</div>
                </div>
            </div>

            @if ($services->isNotEmpty())
                <div class="dovira-ai-textbox">
                    <div class="dovira-ai-label">Напрями / послуги</div>
                    <div class="dovira-ai-chips">
                        @foreach ($services as $service)
                            <span class="dovira-ai-chip">{{ $service }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="dovira-ai-fields">
                @foreach ($mainFields as $key => $label)
                    <div class="dovira-ai-field">
                        <div class="dovira-ai-label">{{ $label }}</div>
                        <div class="dovira-ai-value">{{ $toText(data_get($suggested, $key)) }}</div>
                    </div>
                @endforeach
            </div>

            @if ($socialLinks->isNotEmpty())
                <div class="dovira-ai-textbox">
                    <div class="dovira-ai-label">Соцмережі</div>
                    <div class="dovira-ai-list">
                        @foreach ($socialLinks as $network => $url)
                            @php
                                $socialUrl = $toUrl($url);
                            @endphp
                            <div>
                                <span class="dovira-ai-pill">{{ $toText($network) }}</span>
                                @if ($socialUrl !== '')
                                    <a class="dovira-ai-url" href="{{ $socialUrl }}" target="_blank" rel="noopener noreferrer">{{ $socialUrl }}</a>
                                @else
                                    <span class="dovira-ai-muted">{{ $toText($url) }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if (! empty(data_get($suggested, 'warnings')) || ! empty(data_get($suggested, 'confidence_notes')))
                <div class="dovira-ai-grid">
                    <div class="dovira-ai-textbox">
                        <div class="dovira-ai-label">Попередження</div>
                        <div class="dovira-ai-list">
                            @forelse ((array) data_get($suggested, 'warnings', []) as $warning)
                                <div class="dovira-ai-muted">{{ $toText($warning) }}</div>
                            @empty
                                <div class="dovira-ai-muted">Немає попереджень.</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="dovira-ai-textbox">
                        <div class="dovira-ai-label">Нотатки confidence</div>
                        <div class="dovira-ai-list">
                            @forelse ((array) data_get($suggested, 'confidence_notes', []) as $note)
                                <div class="dovira-ai-muted">{{ $toText($note) }}</div>
                            @empty
                                <div class="dovira-ai-muted">Нотаток немає.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            @endif
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Стартові дані</x-slot>
        <x-slot name="description">Те, що прийшло з імпорту або ручного списку.</x-slot>

        <div class="dovira-ai-fields">
            <div class="dovira-ai-field">
                <div class="dovira-ai-label">Назва</div>
                <div class="dovira-ai-value">{{ $toText($task->raw_name) }}</div>
            </div>
            <div class="dovira-ai-field">
                <div class="dovira-ai-label">Місто</div>
                <div class="dovira-ai-value">{{ $toText($task->raw_city) }}</div>
            </div>
            <div class="dovira-ai-field">
                <div class="dovira-ai-label">Телефон</div>
                <div class="dovira-ai-value">{{ $toText($task->raw_phone) }}</div>
            </div>
            <div class="dovira-ai-field">
                <div class="dovira-ai-label">Сайт / джерело</div>
                <div class="dovira-ai-value">{{ $toText($task->raw_website ?: $task->raw_source_url) }}</div>
            </div>
        </div>

        @if (! empty($rawPayload))
            <details class="dovira-ai-raw">
                <summary>Raw payload</summary>
                <pre>{{ json_encode($rawPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Джерела</x-slot>
        <x-slot name="description">Фактичні дані мають перевірятися за джерелами перед публікацією.</x-slot>

        <div class="dovira-ai-list">
            @forelse ($task->dataSources as $source)
                @php
                    $sourcePayload = is_array($source->raw_payload ?? null) ? $source->raw_payload : [];
                    $sourceTitle = $toText($source->title ?? data_get($sourcePayload, 'title'), $toText($source->source_type, 'Джерело'));
                    $sourceType = $toText($source->source_type, 'source');
                    $sourceUrl = $toUrl($source->url);
                    $foundFields = $normalizeFoundFields($source->found_fields);
                @endphp
                <div class="dovira-ai-source">
                    <div class="dovira-ai-source__head">
                        <div>
                            <div class="dovira-ai-source__title">{{ $sourceTitle }}</div>
                            <div class="dovira-ai-muted">{{ $sourceType }}</div>
                        </div>
                        <span class="dovira-ai-pill dovira-ai-pill--blue">{{ $source->confidence_score ?? 0 }}%</span>
                    </div>

                    @if ($sourceUrl !== '')
                        <a class="dovira-ai-url" href="{{ $sourceUrl }}" target="_blank" rel="noopener noreferrer">{{ $sourceUrl }}</a>
                    @else
                        <div class="dovira-ai-muted">Без URL</div>
                    @endif

                    @if ($foundFields !== [])
                        <div class="dovira-ai-chips">
                            @foreach ($foundFields as $field)
                                <span class="dovira-ai-chip">{{ $toText($field) }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <div class="dovira-ai-muted">Джерела ще не збережені.</div>
            @endforelse
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Зовнішні згадки</x-slot>
        <x-slot name="description">Це не власні відгуки DOVIRA. Вони потребують ручної модерації.</x-slot>

        <div class="dovira-ai-list">
            @forelse ($task->externalMentions as $mention)
                @php
                    $payload = is_array($mention->raw_payload) ? $mention->raw_payload : [];
                    $mentionTitle = $toText($mention->title ?? data_get($payload, 'title'), $toText($mention->source_type, 'Зовнішня згадка'));
                    $reviewText = $toText(data_get($payload, 'review_text'), '');
                    $reviewAuthor = $toText(data_get($payload, 'review_author'), '');
                    $mentionSentiment = $toText($mention->sentiment, 'unknown');
                    $mentionSummary = $toText($mention->summary, '');
                    $mentionUrl = $toUrl($mention->url);
                    $isReviewCandidate = $mentionUrl !== '' && filled($reviewText);
                @endphp
                <div class="dovira-ai-mention">
                    <div class="dovira-ai-mention__head">
                        <div>
                            <div class="dovira-ai-mention__title">{{ $mentionTitle }}</div>
                            <div class="dovira-ai-muted">
                                @if ($reviewAuthor)
                                    {{ $reviewAuthor }} ·
                                @endif
                                {{ $mentionSentiment }}
                                @if ($mention->external_rating)
                                    · {{ $mention->external_rating }} external
                                @endif
                            </div>
                        </div>
                        <div class="dovira-ai-chips">
                            @if ($isReviewCandidate)
                                <span class="dovira-ai-pill dovira-ai-pill--green">Кандидат у відгук</span>
                            @endif
                            <span class="dovira-ai-pill dovira-ai-pill--blue">{{ $mention->confidence_score ?? 0 }}%</span>
                        </div>
                    </div>

                    @if ($mentionUrl !== '')
                        <a class="dovira-ai-url" href="{{ $mentionUrl }}" target="_blank" rel="noopener noreferrer">{{ $mentionUrl }}</a>
                    @else
                        <div class="dovira-ai-muted">Без URL</div>
                    @endif

                    @if (filled($mentionSummary))
                        <div class="dovira-ai-muted">{{ $mentionSummary }}</div>
                    @endif

                    @if (filled($reviewText))
                        <div class="dovira-ai-review__text">{{ $reviewText }}</div>
                    @endif
                </div>
            @empty
                <div class="dovira-ai-muted">Зовнішні згадки ще не знайдені.</div>
            @endforelse
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Чернетки відгуків DOVIRA</x-slot>
        <x-slot name="description">Створюються тільки з джерелом і залишаються на модерації. Вони не впливають на рейтинг до публікації.</x-slot>

        <div class="dovira-ai-list">
            @forelse ($reviewDrafts as $review)
                <div class="dovira-ai-review">
                    <div class="dovira-ai-review__head">
                        <div>
                            <div class="dovira-ai-review__title">{{ $toText($review->author_name ?: $review->external_review_author ?: $review->user?->name, 'Автор не вказаний') }}</div>
                            <div class="dovira-ai-muted">
                                {{ number_format((float) $review->rating, 1) }} / 5
                                @if ($review->external_source_url)
                                    · джерело збережено
                                @endif
                            </div>
                        </div>
                        <span class="dovira-ai-pill dovira-ai-pill--amber">{{ $review->status }}</span>
                    </div>

                    <div class="dovira-ai-review__text">{{ $toText($review->text ?: $review->body) }}</div>

                    @php
                        $reviewSourceUrl = $toUrl($review->external_source_url);
                    @endphp
                    @if ($reviewSourceUrl !== '')
                        <a class="dovira-ai-url" href="{{ $reviewSourceUrl }}" target="_blank" rel="noopener noreferrer">{{ $reviewSourceUrl }}</a>
                    @endif
                </div>
            @empty
                <div class="dovira-ai-muted">Чернеток відгуків ще немає. Вони з’являться тільки якщо AI знайде source-backed публічний відгук або згадку.</div>
            @endforelse
        </div>
    </x-filament::section>

    @if (! empty($duplicates))
        <x-filament::section>
            <x-slot name="heading">Можливі дублікати</x-slot>
            <x-slot name="description">Профіль не потрібно публікувати, поки дублікати не перевірені.</x-slot>

            <details class="dovira-ai-raw" open>
                <summary>Показати знайдені збіги</summary>
                <pre>{{ json_encode($duplicates, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        </x-filament::section>
    @endif
</div>
