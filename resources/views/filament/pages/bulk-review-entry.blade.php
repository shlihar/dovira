<x-filament-panels::page>
    <div class="fi-section-content-ctn max-w-3xl">

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
            {{-- Крок 2: швидкий ввід --}}
            <div
                x-data="{
                    focusAuthor() { this.$nextTick(() => this.$refs.author && this.$refs.author.focus()); }
                }"
                x-on:focus-author.window="focusAuthor()"
                x-init="focusAuthor()"
            >
                <div class="mb-3 flex items-center justify-between gap-3 rounded-xl border border-primary-200 bg-primary-50 px-4 py-3 dark:border-primary-500/30 dark:bg-primary-500/10">
                    <div>
                        <span class="text-xs uppercase tracking-wide text-primary-600 dark:text-primary-300">Профіль</span>
                        <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ $profileName }}</div>
                    </div>
                    <div class="flex items-center gap-4">
                        <a
                            href="https://www.google.com/search?q={{ urlencode($profileName . ' відгуки google') }}"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:underline dark:text-primary-300"
                        >
                            <x-heroicon-o-magnifying-glass class="h-4 w-4" />
                            Відкрити в Google
                        </a>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Додано: {{ $addedCount }}</span>
                        <button type="button" wire:click="clearProfile" class="text-sm text-primary-600 hover:underline dark:text-primary-300">Змінити профіль</button>
                    </div>
                </div>

                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" wire:model="googleBadge" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                        Усі відгуки з бейджем «відгук з Google»
                    </label>
                    <button type="button" wire:click="togglePaste" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-300">
                        {{ $pasteMode ? '← Ввід по одному' : 'Масова вставка з Google →' }}
                    </button>
                </div>

                @if ($pasteMode)
                    {{-- Масова вставка: вставляєш блок з Google, розбираємо на ім'я/дату/текст --}}
                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Вставте скопійований блок відгуків з Google</label>
                        <textarea
                            wire:model="pasteRaw"
                            rows="6"
                            placeholder="Ctrl+A → Ctrl+C на сторінці відгуків Google, потім вставте сюди…"
                            class="mt-2 block w-full rounded-lg border-gray-300 font-mono text-xs shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
                        ></textarea>
                        <div class="mt-2 flex items-center gap-3">
                            <x-filament::button type="button" wire:click="parsePaste" color="gray">Розібрати</x-filament::button>
                            <span class="text-xs text-gray-500">Відповіді власника й службові рядки відкидаються. Рейтинг постав вручну (зірки).</span>
                        </div>

                        @if (count($parsed))
                            <div class="mt-4 space-y-2">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Знайдено: {{ count($parsed) }}</span>
                                        <span class="text-xs text-gray-400">Всі:</span>
                                        <button type="button" wire:click="setAllParsedRating(5)" class="rounded border border-gray-200 px-1.5 py-0.5 text-xs hover:border-amber-400 dark:border-white/10">5★</button>
                                        <button type="button" wire:click="setAllParsedRating(1)" class="rounded border border-gray-200 px-1.5 py-0.5 text-xs hover:border-amber-400 dark:border-white/10">1★</button>
                                    </div>
                                    <x-filament::button type="button" wire:click="publishParsed">Опублікувати всі ({{ count($parsed) }})</x-filament::button>
                                </div>
                                @foreach ($parsed as $idx => $p)
                                    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10" wire:key="parsed-{{ $idx }}">
                                        <div class="flex items-center justify-between gap-3">
                                            <div class="flex items-center gap-2">
                                                <select wire:model.live="parsed.{{ $idx }}.rating"
                                                    class="rounded-lg border-gray-300 py-1 pl-2 pr-7 text-sm font-semibold text-amber-500 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5">
                                                    @for ($s = 5; $s >= 1; $s--)
                                                        <option value="{{ $s }}">{{ str_repeat('★', $s) }} {{ $s }}</option>
                                                    @endfor
                                                </select>
                                                <span class="ml-1 text-sm font-medium text-gray-900 dark:text-white">{{ $p['author'] }}</span>
                                                <span class="text-xs text-gray-400">{{ $p['date'] }}</span>
                                            </div>
                                            <button type="button" wire:click="removeParsed({{ $idx }})" class="text-xs text-danger-600 hover:underline">видалити</button>
                                        </div>
                                        <p class="mt-1 whitespace-pre-line text-sm {{ $p['text'] === '' ? 'italic text-gray-400' : 'text-gray-600 dark:text-gray-300' }}">{{ $p['text'] === '' ? 'без тексту (лише оцінка)' : $p['text'] }}</p>
                                    </div>
                                @endforeach
                            </div>
                        @elseif (trim($pasteRaw) !== '')
                            <p class="mt-2 text-sm text-gray-500">Натисніть «Розібрати», щоб витягти відгуки.</p>
                        @endif
                    </div>
                @endif

                @unless ($pasteMode)
                <form wire:submit="save" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="sm:col-span-2">
                            <label class="text-xs font-medium text-gray-600 dark:text-gray-300">Ім'я автора</label>
                            <input
                                type="text"
                                x-ref="author"
                                wire:model="authorName"
                                class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
                            >
                            @error('authorName') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="text-xs font-medium text-gray-600 dark:text-gray-300">Рейтинг</label>
                            <div class="mt-1 flex items-center gap-1" role="radiogroup" aria-label="Рейтинг">
                                @for ($s = 1; $s <= 5; $s++)
                                    <button
                                        type="button"
                                        wire:click="$set('rating', {{ $s }})"
                                        class="text-2xl leading-none transition-transform hover:scale-110 {{ $rating >= $s ? 'text-amber-400' : 'text-gray-300 dark:text-gray-600' }}"
                                        aria-label="{{ $s }} зірок"
                                        title="{{ $s }}★ (клавіша {{ $s }})"
                                    >★</button>
                                @endfor
                                <span class="ml-1 text-sm text-gray-500">{{ $rating }}/5</span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="text-xs font-medium text-gray-600 dark:text-gray-300">Текст відгуку</label>
                        <textarea
                            wire:model="body"
                            rows="4"
                            class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
                            x-on:keydown.enter.meta="$el.closest('form').requestSubmit()"
                            x-on:keydown.enter.ctrl="$el.closest('form').requestSubmit()"
                        ></textarea>
                        @error('body') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
                        <p class="mt-1 text-xs text-gray-400">Enter у полі імені/рейтингу — публікує. У тексті: Ctrl/⌘+Enter.</p>
                    </div>

                    <div class="mt-3 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <label class="text-xs font-medium text-gray-600 dark:text-gray-300">Дата</label>
                            <div class="mt-1 flex items-center gap-2">
                                <input
                                    type="date"
                                    wire:model="reviewDate"
                                    class="block rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
                                >
                                <div class="flex flex-wrap gap-1 text-xs">
                                    <button type="button" wire:click="setDate('today')" class="rounded-md border border-gray-200 px-2 py-1 hover:border-primary-400 dark:border-white/10">сьогодні</button>
                                    <button type="button" wire:click="setDate('week')" class="rounded-md border border-gray-200 px-2 py-1 hover:border-primary-400 dark:border-white/10">−тижд</button>
                                    <button type="button" wire:click="setDate('month')" class="rounded-md border border-gray-200 px-2 py-1 hover:border-primary-400 dark:border-white/10">−міс</button>
                                    <button type="button" wire:click="setDate('halfyear')" class="rounded-md border border-gray-200 px-2 py-1 hover:border-primary-400 dark:border-white/10">−півр</button>
                                    <button type="button" wire:click="setDate('year')" class="rounded-md border border-gray-200 px-2 py-1 hover:border-primary-400 dark:border-white/10">−рік</button>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($lastReviewId)
                                <button type="button" wire:click="deleteLast" class="text-sm text-danger-600 hover:underline">↩ Скасувати останній</button>
                            @endif
                            <x-filament::button type="submit">
                                Опублікувати й далі
                            </x-filament::button>
                        </div>
                    </div>
                </form>
                @endunless

                @if (count($recent))
                    <div class="mt-4">
                        <span class="text-xs font-medium text-gray-500">Щойно додані:</span>
                        <ul class="mt-1 space-y-1">
                            @foreach ($recent as $r)
                                <li class="text-sm text-gray-700 dark:text-gray-200">{{ str_repeat('★', $r['rating']) }} <span class="text-gray-500">{{ $r['author'] }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
