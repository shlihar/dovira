@php
    $fmt = fn (int $value) => number_format($value, 0, '.', ' ');
@endphp

<div class="space-y-3">
    @forelse ($items as $item)
        <div>
            <div class="mb-1 flex items-center justify-between gap-3 text-sm">
                <span class="min-w-0 truncate font-medium text-gray-950 dark:text-white" title="{{ $item['label'] }}">{{ $item['label'] }}</span>
                <span class="shrink-0 text-gray-500 dark:text-gray-400">{{ $fmt((int) $item['count']) }} · {{ $item['percent'] }}%</span>
            </div>
            <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                <div class="h-full rounded-full bg-primary-500" style="width: {{ max(2, (int) $item['percent']) }}%;"></div>
            </div>
        </div>
    @empty
        <div class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-white/15 dark:text-gray-400">
            {{ $empty }}
        </div>
    @endforelse
</div>
