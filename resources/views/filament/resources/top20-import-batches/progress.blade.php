@php
    $batch = $record ?? null;
    $progress = (array) data_get($batch?->options ?? [], 'progress', []);
    $totalItems = (int) ($progress['total_items'] ?? $batch?->items()->count() ?? 0);
    $processedItems = (int) ($progress['processed_items'] ?? ($batch?->items()->whereIn('status', ['imported', 'skipped', 'error'])->count() ?? 0));
    $pendingItems = (int) ($progress['pending_items'] ?? max(0, $totalItems - $processedItems));
    $errorItems = (int) ($batch?->items()->where('status', 'error')->count() ?? 0);
    $importedItems = (int) ($batch?->items()->where('status', 'imported')->count() ?? 0);
    $skippedItems = (int) ($batch?->items()->where('status', 'skipped')->count() ?? 0);
    $progressPercent = $totalItems > 0
        ? max(0, min(100, (int) ($progress['percent'] ?? floor(($processedItems / $totalItems) * 100))))
        : (in_array($batch?->status, ['completed', 'completed_with_errors'], true) ? 100 : 0);
    $currentItem = is_array($progress['current'] ?? null) ? $progress['current'] : null;
@endphp

<style>
    .dovira-top20-progress {
        display: grid;
        gap: 1rem;
    }

    .dovira-top20-progress__card {
        border: 1px solid rgba(148, 163, 184, .22);
        border-radius: 1rem;
        background: rgba(255, 255, 255, .78);
        padding: 1rem 1.05rem;
    }

    .dark .dovira-top20-progress__card {
        border-color: rgba(255, 255, 255, .12);
        background: rgba(255, 255, 255, .035);
    }

    .dovira-top20-progress__head {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        align-items: center;
        color: #0f172a;
        font-size: .95rem;
        font-weight: 650;
    }

    .dark .dovira-top20-progress__head {
        color: #fff;
    }

    .dovira-top20-progress__meta {
        margin-top: .35rem;
        color: #64748b;
        font-size: .82rem;
        line-height: 1.45;
    }

    .dovira-top20-progress__bar {
        margin-top: .9rem;
        height: .72rem;
        overflow: hidden;
        border-radius: 999px;
        background: rgba(148, 163, 184, .18);
    }

    .dovira-top20-progress__fill {
        height: 100%;
        width: var(--progress, 0%);
        border-radius: inherit;
        background: linear-gradient(90deg, #f59e0b, #2563eb);
        transition: width .35s ease;
    }

    .dovira-top20-progress__stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: .75rem;
    }

    .dovira-top20-progress__stat {
        border: 1px solid rgba(148, 163, 184, .18);
        border-radius: .95rem;
        padding: .85rem .9rem;
        background: rgba(248, 250, 252, .88);
    }

    .dark .dovira-top20-progress__stat {
        border-color: rgba(255, 255, 255, .08);
        background: rgba(255, 255, 255, .03);
    }

    .dovira-top20-progress__label {
        color: #64748b;
        font-size: .78rem;
        font-weight: 600;
        line-height: 1.35;
    }

    .dovira-top20-progress__value {
        margin-top: .45rem;
        color: #0f172a;
        font-size: 1.6rem;
        font-weight: 700;
        line-height: 1;
    }

    .dark .dovira-top20-progress__value {
        color: #fff;
    }
</style>

<div class="dovira-top20-progress" wire:poll.5s>
    <div class="dovira-top20-progress__card">
        <div class="dovira-top20-progress__head">
            <span>Реальний прогрес імпорту</span>
            <span>{{ $processedItems }} / {{ $totalItems ?: '0' }}</span>
        </div>
        <div class="dovira-top20-progress__meta">
            @if ($currentItem)
                Зараз обробляється:
                <strong>{{ $currentItem['name'] ?: 'Профіль без назви' }}</strong>
                @if (!empty($currentItem['position']) && $totalItems > 0)
                    · {{ $currentItem['position'] }} з {{ $totalItems }}
                @endif
            @elseif (in_array($batch?->status, ['completed', 'completed_with_errors'], true))
                Імпорт завершено.
            @elseif ($batch?->status === 'queued')
                Імпорт у черзі.
            @else
                Очікування даних прогресу.
            @endif
        </div>
        <div class="dovira-top20-progress__bar" aria-hidden="true">
            <div class="dovira-top20-progress__fill" style="--progress: {{ $progressPercent }}%;"></div>
        </div>
    </div>

    <div class="dovira-top20-progress__stats">
        <div class="dovira-top20-progress__stat">
            <div class="dovira-top20-progress__label">Оброблено</div>
            <div class="dovira-top20-progress__value">{{ $processedItems }}</div>
        </div>
        <div class="dovira-top20-progress__stat">
            <div class="dovira-top20-progress__label">Ще в черзі</div>
            <div class="dovira-top20-progress__value">{{ $pendingItems }}</div>
        </div>
        <div class="dovira-top20-progress__stat">
            <div class="dovira-top20-progress__label">Імпортовано</div>
            <div class="dovira-top20-progress__value">{{ $importedItems }}</div>
        </div>
        <div class="dovira-top20-progress__stat">
            <div class="dovira-top20-progress__label">Пропущено</div>
            <div class="dovira-top20-progress__value">{{ $skippedItems }}</div>
        </div>
        <div class="dovira-top20-progress__stat">
            <div class="dovira-top20-progress__label">Помилки</div>
            <div class="dovira-top20-progress__value">{{ $errorItems }}</div>
        </div>
    </div>
</div>
