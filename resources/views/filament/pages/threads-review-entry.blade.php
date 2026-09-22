<x-filament-panels::page>
    <div class="fi-section-content-ctn max-w-2xl">

        {{-- Крок 1: вибір профілю --}}
        @if (! $profileId)
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Оберіть профіль</label>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Почніть вводити назву профілю…"
                    class="mt-2 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
                    autofocus
                >
                @if (count($this->matches))
                    <ul class="mt-2 divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($this->matches as $m)
                            <li>
                                <button
                                    type="button"
                                    wire:click="selectProfile({{ $m['id'] }}, @js($m['name']))"
                                    class="flex w-full items-center justify-between gap-3 px-2 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5"
                                >
                                    <span class="font-medium text-gray-900 dark:text-white">{{ $m['name'] }}</span>
                                    <span class="text-xs text-gray-500">{{ $m['city'] ?: '—' }} · {{ $m['reviews'] }} відг.</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @elseif (mb_strlen(trim($search)) >= 2)
                    <p class="mt-2 text-sm text-gray-500">Нічого не знайдено.</p>
                @endif
            </div>
        @else
            {{-- Крок 2: швидкий ввід відгуку з Threads --}}
            <div
                x-data="{ focusAuthor() { this.$nextTick(() => this.$refs.author && this.$refs.author.focus()); } }"
                x-on:focus-author.window="focusAuthor()"
                x-init="focusAuthor()"
            >
                <div class="mb-3 flex items-center justify-between gap-3 rounded-xl border border-primary-200 bg-primary-50 px-4 py-3 dark:border-primary-500/30 dark:bg-primary-500/10">
                    <div>
                        <span class="text-xs uppercase tracking-wide text-primary-600 dark:text-primary-300">Профіль</span>
                        <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ $profileName }}</div>
                    </div>
                    <div class="flex items-center gap-4">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Додано: {{ $addedCount }}</span>
                        <button type="button" wire:click="clearProfile" class="text-sm text-primary-600 hover:underline dark:text-primary-300">Змінити профіль</button>
                    </div>
                </div>

                <form wire:submit="save" class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Автор відгуку</label>
                        <input
                            type="text" x-ref="author" wire:model="authorName"
                            placeholder="Ім'я автора з Threads"
                            class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
                        >
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Оцінка</label>
                        <div class="mt-1 flex items-center gap-1">
                            @for ($i = 1; $i <= 5; $i++)
                                <button type="button" wire:click="setRating({{ $i }})"
                                    class="text-2xl leading-none {{ $i <= $rating ? 'text-amber-400' : 'text-gray-300 dark:text-gray-600' }}"
                                    aria-label="{{ $i }}">★</button>
                            @endfor
                            <span class="ml-2 text-sm text-gray-500">{{ $rating }}/5</span>
                        </div>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Текст відгуку</label>
                        <textarea wire:model="body" rows="4"
                            placeholder="Скопіюйте текст відгуку з Threads…"
                            class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"></textarea>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Посилання на пост у Threads <span class="text-gray-400">(необовʼязково)</span></label>
                        <input type="url" wire:model="threadsUrl"
                            placeholder="https://www.threads.net/@user/post/…"
                            class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white">
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Коментарі під відгуком <span class="text-gray-400">(по одному в рядку: «Ім'я: текст»)</span></label>
                        <textarea wire:model="comments" rows="3"
                            placeholder="Марія: підтверджую, теж зверталась&#10;Ігор: дякую за пораду"
                            class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"></textarea>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Фото до відгуку <span class="text-gray-400">(скріншоти з Threads, до 6)</span></label>
                        <input type="file" wire:model="photos" multiple accept="image/*"
                            class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-1.5 file:text-primary-700 hover:file:bg-primary-100 dark:text-gray-300">
                        <div wire:loading wire:target="photos" class="mt-1 text-xs text-gray-500">Завантаження фото…</div>
                        @if (count($photos))
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($photos as $photo)
                                    @if (is_object($photo) && method_exists($photo, 'temporaryUrl'))
                                        <img src="{{ $photo->temporaryUrl() }}" class="h-16 w-16 rounded object-cover border border-gray-200 dark:border-white/10" alt="">
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center gap-3 pt-1">
                        <x-filament::button type="submit">Опублікувати</x-filament::button>
                        @if ($lastReviewId)
                            <button type="button" wire:click="deleteLast" class="text-sm text-danger-600 hover:underline">Скасувати останній</button>
                        @endif
                    </div>
                </form>

                @if (count($recent))
                    <div class="mt-4 rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                        <div class="text-xs uppercase tracking-wide text-gray-500">Щойно додані</div>
                        <ul class="mt-2 space-y-1">
                            @foreach ($recent as $r)
                                <li class="flex items-center justify-between text-sm">
                                    <span class="text-gray-900 dark:text-white">{{ $r['author'] }}</span>
                                    <span class="text-gray-500">{{ $r['rating'] }}★@if($r['comments']) · {{ $r['comments'] }} комент.@endif</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
