<x-filament-widgets::widget>
    @if ($isOpen)
        <x-filament::section
            id="dashboard-site-views-sources"
            heading="Звідки прийшли користувачі"
            description="Розподіл усіх переглядів сторінок сайту за джерелами у вибраному періоді."
        >
            <div class="flex items-center justify-between gap-3">
                <div class="text-sm fi-text-color-500">
                    Всього переглядів за період:
                    <span class="font-semibold fi-text-color-900 dark:fi-text-color-100">
                        {{ number_format($this->totalViews, 0, '.', ' ') }}
                    </span>
                </div>

                <x-filament::button color="gray" icon="heroicon-m-x-mark" size="xs" wire:click="hideSources">
                    Сховати
                </x-filament::button>
            </div>

            <div class="mt-4 space-y-3">
                @forelse ($this->sources as $source)
                    <div>
                        <div class="mb-1 flex items-center justify-between text-sm">
                            <span class="font-medium">{{ $source['label'] }}</span>
                            <span class="fi-text-color-500">
                                {{ number_format($source['count'], 0, '.', ' ') }} · {{ $source['percent'] }}%
                            </span>
                        </div>

                        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                            <div
                                class="h-full rounded-full bg-primary-500 transition-all"
                                style="width: {{ $source['percent'] }}%;"
                            ></div>
                        </div>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-gray-300 p-4 text-sm fi-text-color-500 dark:border-white/15">
                        За обраний період даних по джерелах немає.
                    </div>
                @endforelse
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
