<section class="card pro-overview-card pro-overview-completion-card {{ $cardClass ?? '' }}">
    <div class="pro-overview-card__head">
        <h2>{{ $title ?? 'Заповнення профілю' }}</h2>
    </div>

    <div
        class="pro-overview-completion-card__ring"
        style="--progress: {{ $progress ?? 0 }}; --circumference: 263.89; --dash-offset: {{ round(263.89 - ((263.89 * ($progress ?? 0)) / 100), 2) }};"
    >
        <svg viewBox="0 0 96 96" aria-hidden="true">
            <circle class="pro-overview-completion-card__ring-track" cx="48" cy="48" r="42"></circle>
            <circle class="pro-overview-completion-card__ring-progress" cx="48" cy="48" r="42"></circle>
        </svg>
        <div>
            <strong>{{ $progress ?? 0 }}%</strong>
            <span>{{ $progressLabel ?? 'заповнено' }}</span>
        </div>
    </div>

    @if (! empty($summary))
        <p class="pro-overview-completion-card__summary">{{ $summary }}</p>
    @endif

    <ul class="pro-overview-completion-card__list">
        @foreach (($items ?? []) as $item)
            <li class="{{ ! empty($item['done']) ? 'is-done' : '' }}">
                <i class="fa-solid {{ ! empty($item['done']) ? 'fa-circle-check' : 'fa-circle' }}" aria-hidden="true"></i>
                <span>{{ $item['label'] }}</span>
            </li>
        @endforeach
    </ul>

    @if (! empty($action))
        @if (! empty($action['tab_target']))
            <button
                type="button"
                class="btn btn--profile-contact pro-overview-completion-card__action"
                data-pro-tab-trigger
                data-pro-tab-target="{{ $action['tab_target'] }}"
                @if (! empty($action['tab_scroll'])) data-pro-tab-scroll="{{ $action['tab_scroll'] }}" @endif
            >
                @if (! empty($action['icon']))
                    <i class="fa-regular {{ $action['icon'] }}" aria-hidden="true"></i>
                @endif
                <span>{{ $action['label'] ?? '' }}</span>
            </button>
        @elseif (! empty($action['wire_click']))
            <button type="button" class="btn btn--profile-contact pro-overview-completion-card__action" wire:click="{{ $action['wire_click'] }}">
                @if (! empty($action['icon']))
                    <i class="fa-regular {{ $action['icon'] }}" aria-hidden="true"></i>
                @endif
                <span>{{ $action['label'] ?? '' }}</span>
            </button>
        @else
            <a class="btn btn--profile-contact pro-overview-completion-card__action" href="{{ $action['href'] ?? '#' }}">
                @if (! empty($action['icon']))
                    <i class="fa-regular {{ $action['icon'] }}" aria-hidden="true"></i>
                @endif
                <span>{{ $action['label'] ?? '' }}</span>
            </a>
        @endif
    @endif
</section>
