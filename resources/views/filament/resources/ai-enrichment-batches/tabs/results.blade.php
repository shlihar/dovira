@php
    $batch = $record ?? null;
    $tasks = $batch?->tasks()->with(['profile', 'category'])->orderByDesc('updated_at')->limit(12)->get() ?? collect();
    $statusCounts = $batch?->tasks()
        ->selectRaw('status, count(*) as aggregate')
        ->groupBy('status')
        ->pluck('aggregate', 'status') ?? collect();
    $progress = (array) data_get($batch?->options ?? [], 'progress', []);
    $totalItems = (int) ($progress['total_items'] ?? $batch?->total_items ?? 0);
    $processedItems = (int) ($progress['processed_items'] ?? 0);
    $progressPercent = $totalItems > 0
        ? max(0, min(100, (int) ($progress['percent'] ?? floor(($processedItems / $totalItems) * 100))))
        : (in_array($batch?->status, ['completed', 'completed_with_errors'], true) ? 100 : 0);
    $currentTask = is_array($progress['current'] ?? null) ? $progress['current'] : null;
    $workerCount = count(app(\App\Services\Queue\AiEnrichmentQueueWorkerManager::class)->runningWorkerPids());
    $needsWorker = in_array($batch?->status, ['queued', 'processing'], true);

    $cards = [
        ['label' => 'Всього рядків', 'value' => (int) ($batch?->total_items ?? 0), 'tone' => 'blue'],
        ['label' => 'Створено чернеток', 'value' => (int) ($batch?->drafts_created ?? 0), 'tone' => 'green'],
        ['label' => 'Оновлено профілів', 'value' => (int) ($batch?->profiles_updated ?? 0), 'tone' => 'cyan'],
        ['label' => 'Можливі дублікати', 'value' => (int) ($batch?->duplicates_found ?? 0), 'tone' => 'amber'],
        ['label' => 'На перевірці', 'value' => (int) ($batch?->needs_review_count ?? 0), 'tone' => 'amber'],
        ['label' => 'Помилки', 'value' => (int) ($batch?->errors_count ?? 0), 'tone' => 'red'],
    ];

    $statusLabels = [
        'pending' => 'Очікує',
        'processing' => 'В процесі',
        'queued' => 'У черзі',
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
        'queued' => 'blue',
        'data_found' => 'green',
        'needs_review' => 'amber',
        'possible_duplicate' => 'amber',
        'completed' => 'green',
        'error' => 'red',
        'rejected' => 'red',
    ];
@endphp

<style>
    .dovira-ai-results {
        display: grid;
        gap: 1.25rem;
    }

    .dovira-ai-results__stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: .75rem;
    }

    .dovira-ai-progress {
        overflow: hidden;
        border: 1px solid var(--gray-200, rgba(148, 163, 184, .25));
        border-radius: 1rem;
        background: color-mix(in srgb, white 86%, transparent);
        padding: 1rem;
    }

    .dark .dovira-ai-progress {
        border-color: rgba(255, 255, 255, .1);
        background: rgba(255, 255, 255, .035);
    }

    .dovira-ai-progress__head {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        color: var(--gray-950, #020617);
        font-size: .92rem;
        font-weight: 650;
    }

    .dark .dovira-ai-progress__head {
        color: #fff;
    }

    .dovira-ai-progress__meta {
        margin-top: .35rem;
        color: var(--gray-500, #64748b);
        font-size: .8rem;
    }

    .dovira-ai-progress__bar {
        position: relative;
        height: .65rem;
        margin-top: .8rem;
        overflow: hidden;
        border-radius: 999px;
        background: rgba(148, 163, 184, .18);
    }

    .dovira-ai-progress__fill {
        height: 100%;
        width: var(--progress, 0%);
        border-radius: inherit;
        background: linear-gradient(90deg, #2563eb, #06b6d4);
        transition: width .45s ease;
    }

    .dovira-ai-worker-alert {
        border: 1px solid rgba(245, 158, 11, .35);
        border-radius: .85rem;
        background: rgba(245, 158, 11, .08);
        color: #92400e;
        padding: .8rem .9rem;
        font-size: .84rem;
        line-height: 1.45;
    }

    .dark .dovira-ai-worker-alert {
        color: #fcd34d;
        background: rgba(245, 158, 11, .12);
    }

    .dovira-ai-stat {
        position: relative;
        overflow: hidden;
        min-width: 0;
        min-height: 112px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        padding: 1rem 1.05rem;
        border: 1px solid var(--gray-200, rgba(148, 163, 184, .25));
        border-radius: 1.15rem;
        background: color-mix(in srgb, var(--gray-50, #f8fafc) 88%, transparent);
    }

    .dark .dovira-ai-stat {
        border-color: rgba(255, 255, 255, .12);
        background: rgba(255, 255, 255, .035);
    }

    .dovira-ai-stat::after {
        content: "";
        position: absolute;
        right: -22px;
        bottom: -34px;
        width: 98px;
        height: 98px;
        border-radius: 999px;
        background: color-mix(in srgb, var(--accent, #3b82f6) 22%, transparent);
        filter: blur(2px);
    }

    .dovira-ai-stat--green { --accent: #22c55e; }
    .dovira-ai-stat--cyan { --accent: #06b6d4; }
    .dovira-ai-stat--amber { --accent: #f59e0b; }
    .dovira-ai-stat--red { --accent: #ef4444; }
    .dovira-ai-stat--blue { --accent: #3b82f6; }

    .dovira-ai-stat__label {
        position: relative;
        z-index: 1;
        color: var(--gray-500, #64748b);
        max-width: none;
        font-size: .78rem;
        font-weight: 600;
        line-height: 1.35;
        overflow-wrap: anywhere;
    }

    .dovira-ai-stat__value {
        position: relative;
        z-index: 1;
        margin-top: .55rem;
        color: var(--gray-950, #020617);
        font-size: 2rem;
        font-weight: 650;
        line-height: 1;
    }

    .dark .dovira-ai-stat__value {
        color: #fff;
    }

    .dovira-ai-grid {
        display: grid;
        grid-template-columns: minmax(260px, .75fr) minmax(0, 1.45fr);
        gap: 1rem;
        align-items: start;
    }

    .dovira-ai-status-list,
    .dovira-ai-task-list {
        display: grid;
        gap: .625rem;
    }

    .dovira-ai-status-row,
    .dovira-ai-task-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: .75rem .85rem;
        border: 1px solid var(--gray-200, rgba(148, 163, 184, .25));
        border-radius: .95rem;
        background: color-mix(in srgb, white 82%, transparent);
    }

    .dark .dovira-ai-status-row,
    .dark .dovira-ai-task-row {
        border-color: rgba(255, 255, 255, .1);
        background: rgba(255, 255, 255, .035);
    }

    .dovira-ai-task-row {
        align-items: flex-start;
        min-width: 0;
    }

    .dovira-ai-task-row > div:first-child {
        min-width: 0;
    }

    .dovira-ai-task-row__title {
        color: var(--gray-950, #020617);
        font-size: .92rem;
        font-weight: 650;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .dark .dovira-ai-task-row__title {
        color: #fff;
    }

    .dovira-ai-task-row__meta {
        margin-top: .2rem;
        color: var(--gray-500, #64748b);
        font-size: .8rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .dovira-ai-pill {
        display: inline-flex;
        align-items: center;
        white-space: nowrap;
        border-radius: 999px;
        padding: .25rem .55rem;
        color: var(--gray-700, #334155);
        background: var(--gray-100, #f1f5f9);
        font-size: .75rem;
        font-weight: 650;
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

    @media (max-width: 900px) {
        .dovira-ai-grid {
            grid-template-columns: 1fr;
        }

        .dovira-ai-results__stats {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 560px) {
        .dovira-ai-results__stats {
            grid-template-columns: 1fr;
        }
    }
</style>

@if (! $batch)
    <x-filament::section>
        <div class="text-sm">Результати будуть доступні після створення запуску.</div>
    </x-filament::section>
@else
    <div class="dovira-ai-results" wire:poll.5s>
        @if ($needsWorker && $workerCount === 0)
            <div class="dovira-ai-worker-alert">
                AI worker не запущений. Запустіть <code>composer dev</code> або <code>php artisan dovira:ai-enrichment:work</code>, після цього обробка продовжиться з черги.
            </div>
        @endif

        <div class="dovira-ai-progress">
            <div class="dovira-ai-progress__head">
                <span>Прогрес обробки</span>
                <span>{{ $progressPercent }}%</span>
            </div>
            <div class="dovira-ai-progress__meta">
                Оброблено {{ $processedItems }} з {{ $totalItems }}.
                @if ($currentTask)
                    Зараз: #{{ $currentTask['row_number'] ?? '?' }} {{ $currentTask['name'] ?? '' }}
                @elseif ($batch?->status === 'queued')
                    Очікує запуску worker.
                @elseif (in_array($batch?->status, ['completed', 'completed_with_errors'], true))
                    Завершено.
                @endif
            </div>
            <div class="dovira-ai-progress__bar" aria-label="Прогрес AI-збагачення">
                <div class="dovira-ai-progress__fill" style="--progress: {{ $progressPercent }}%"></div>
            </div>
        </div>

        <div class="dovira-ai-results__stats">
            @foreach ($cards as $card)
                <div class="dovira-ai-stat dovira-ai-stat--{{ $card['tone'] }}">
                    <div class="dovira-ai-stat__label">{{ $card['label'] }}</div>
                    <div class="dovira-ai-stat__value">{{ $card['value'] }}</div>
                </div>
            @endforeach
        </div>

        <div class="dovira-ai-grid">
            <x-filament::section>
                <x-slot name="heading">Статуси задач</x-slot>
                <x-slot name="description">Швидкий зріз якості імпорту та AI-обробки.</x-slot>

                <div class="dovira-ai-status-list">
                    @forelse ($statusCounts as $status => $count)
                        <div class="dovira-ai-status-row">
                            <span>{{ $statusLabels[$status] ?? $status }}</span>
                            <span class="dovira-ai-pill dovira-ai-pill--{{ $statusTones[$status] ?? 'blue' }}">{{ $count }}</span>
                        </div>
                    @empty
                        <div class="text-sm">Задач ще немає.</div>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Останні задачі</x-slot>
                <x-slot name="description">Повний список і дії доступні нижче у таблиці задач.</x-slot>

                <div class="dovira-ai-task-list">
                    @forelse ($tasks as $task)
                        <div class="dovira-ai-task-row">
                            <div>
                                <div class="dovira-ai-task-row__title">{{ $task->raw_name }}</div>
                                <div class="dovira-ai-task-row__meta">
                                    {{ $task->profile?->name ?? 'Профіль ще не створено' }}
                                    @if($task->category?->name)
                                        · {{ $task->category->name }}
                                    @endif
                                </div>
                            </div>
                            <div style="display:flex; gap:.4rem; align-items:center; flex-wrap:wrap; justify-content:flex-end;">
                                <span class="dovira-ai-pill dovira-ai-pill--{{ $statusTones[$task->status] ?? 'blue' }}">{{ $statusLabels[$task->status] ?? $task->status }}</span>
                                <span class="dovira-ai-pill">{{ $task->confidence_score ?? 0 }}%</span>
                            </div>
                        </div>
                    @empty
                        <div class="text-sm">Задач ще немає.</div>
                    @endforelse
                </div>
            </x-filament::section>
        </div>
    </div>
@endif
