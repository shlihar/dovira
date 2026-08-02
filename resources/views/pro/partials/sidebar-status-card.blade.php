<section class="pro-account-sidebar-status {{ $cardClass ?? '' }}" @if (! empty($id)) id="{{ $id }}" @endif>
    <div class="pro-account-sidebar-status__head">
        <h2>{{ $title }}</h2>
        @if (! empty($badge))
            <span class="entity-badge entity-badge--pro best-lawyer-card__pro-badge">
                @if (! empty($badge['icon']))
                    <i class="fa-solid {{ $badge['icon'] }}" aria-hidden="true"></i>
                @endif
                {{ $badge['label'] }}
            </span>
        @endif
    </div>

    <div class="pro-account-sidebar-status__subscription {{ ! empty($topBlock['centered']) ? 'is-centered' : '' }}">
        @if (! empty($topBlock['ring']))
            <div
                class="pro-account-sidebar-status__ring"
                style="--progress: {{ $topBlock['ring']['progress'] ?? 0 }}; --circumference: 263.89; --dash-offset: {{ round(263.89 - ((263.89 * (($topBlock['ring']['progress'] ?? 0))) / 100), 2) }};"
            >
                <svg viewBox="0 0 96 96" aria-hidden="true">
                    <circle class="pro-overview-completion-card__ring-track" cx="48" cy="48" r="42"></circle>
                    <circle class="pro-overview-completion-card__ring-progress" cx="48" cy="48" r="42"></circle>
                </svg>
                <div>
                    <strong>{{ $topBlock['ring']['value'] ?? '0%' }}</strong>
                    @if (! empty($topBlock['ring']['label']))
                        <span>{{ $topBlock['ring']['label'] }}</span>
                    @endif
                </div>
            </div>
        @elseif (! empty($topBlock))
            <div class="pro-account-sidebar-status__subscription-top">
                @if (! empty($topBlock['dot_class']))
                    <span class="pro-account-sidebar-status__dot {{ $topBlock['dot_class'] }}"></span>
                @endif
                <strong>{{ $topBlock['title'] ?? '' }}</strong>
            </div>
        @endif

        @if (! empty($topBlock['text']))
            <p>{{ $topBlock['text'] }}</p>
        @endif
    </div>

    <ul class="pro-account-sidebar-status__list">
        @foreach ($items as $item)
            <li>
                <span class="pro-account-sidebar-status__list-icon">
                    <i class="fa-solid {{ $item['icon'] }}" aria-hidden="true"></i>
                </span>
                <span class="pro-account-sidebar-status__list-label">{{ $item['label'] }}</span>
                <i class="fa-solid {{ match($item['state'] ?? 'muted') {
                    'success' => 'fa-circle-check is-done',
                    'warning' => 'fa-circle-exclamation is-warning',
                    'info' => 'fa-clock is-info',
                    default => 'fa-circle is-muted',
                } }}" aria-hidden="true"></i>
            </li>
        @endforeach
    </ul>

    @if (! empty($action))
        <a class="btn btn--profile-contact pro-account-sidebar-status__cta" href="{{ $action['href'] ?? '#' }}">
            <span>{{ $action['label'] ?? '' }}</span>
            @if (! empty($action['icon']))
                <i class="fa-solid {{ $action['icon'] }}" aria-hidden="true"></i>
            @endif
        </a>
    @endif
</section>
