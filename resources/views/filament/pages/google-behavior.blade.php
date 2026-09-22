<x-filament-panels::page>
    @php
        $summary = $this->summary();
        $landingPages = $this->landingPages();
        $searches = $this->searches();
        $siteActions = $this->siteActions();
        $profiles = $this->profiles();
        $profileActions = $this->profileActions();
        $fmt = fn (int $value) => number_format($value, 0, '.', ' ');
    @endphp

    <div class="space-y-6">
        <x-filament::section
            heading="Поведінка користувачів із Google"
            description="Люди, які зайшли з organic/referrer Google або UTM source=google. Далі події звʼязуються по visitor_id."
        >
            <div class="max-w-xs">
                <label class="mb-1 block text-sm font-medium">Період</label>
                <select
                    wire:model.live="period"
                    class="block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-gray-900"
                >
                    <option value="today">Сьогодні</option>
                    <option value="last_7">Останні 7 днів</option>
                    <option value="last_30">Останні 30 днів</option>
                    <option value="last_90">Останні 90 днів</option>
                    <option value="all_time">Весь час</option>
                </select>
            </div>
        </x-filament::section>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['label' => 'Google-відвідувачі', 'value' => $summary['visitors']],
                ['label' => 'Google-перегляди', 'value' => $summary['google_page_views']],
                ['label' => 'Пошуків на сайті', 'value' => $summary['site_searches']],
                ['label' => 'Шукали людей', 'value' => $summary['searched_visitors']],
                ['label' => 'Переглядів профілів', 'value' => $summary['profile_views']],
                ['label' => 'Людей у профілях', 'value' => $summary['profile_visitors']],
                ['label' => 'Кліків на контакти', 'value' => $summary['contact_clicks']],
                ['label' => 'Відгуків надіслали', 'value' => $summary['review_submits']],
            ] as $card)
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $fmt((int) $card['value']) }}</div>
                </div>
            @endforeach
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-filament::section heading="З яких сторінок починали">
                @include('filament.pages.partials.google-behavior-bars', ['items' => $landingPages, 'empty' => 'Немає Google-переходів за період.'])
            </x-filament::section>

            <x-filament::section heading="Що шукали після входу з Google">
                @include('filament.pages.partials.google-behavior-bars', ['items' => $searches, 'empty' => 'Google-відвідувачі не користувались пошуком за період.'])
            </x-filament::section>

            <x-filament::section heading="Дії на сайті після Google">
                @include('filament.pages.partials.google-behavior-bars', ['items' => $siteActions, 'empty' => 'Дій ще немає.'])
            </x-filament::section>

            <x-filament::section heading="Дії на профілях">
                @include('filament.pages.partials.google-behavior-bars', ['items' => $profileActions, 'empty' => 'Дій у профілях ще немає.'])
            </x-filament::section>
        </div>

        <x-filament::section heading="Які профілі відкривали з Google">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2">Профіль</th>
                            <th class="px-3 py-2 text-right">Перегляди</th>
                            <th class="px-3 py-2 text-right">Люди</th>
                            <th class="px-3 py-2 text-right">Контакти</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @forelse ($profiles as $profile)
                            <tr>
                                <td class="px-3 py-3">
                                    <div class="font-medium text-gray-950 dark:text-white">{{ $profile['name'] }}</div>
                                    <div class="text-xs text-gray-500">
                                        {{ $profile['city'] ?: '—' }}
                                        @if ($profile['slug'])
                                            · <a class="text-primary-600 hover:underline" href="{{ route('profile.show', ['slug' => $profile['slug']]) }}" target="_blank">відкрити</a>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-3 py-3 text-right font-medium">{{ $fmt($profile['views']) }}</td>
                                <td class="px-3 py-3 text-right">{{ $fmt($profile['visitors']) }}</td>
                                <td class="px-3 py-3 text-right">{{ $fmt($profile['contacts']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-3 py-6 text-center text-gray-500">Профілів з Google-переходів за період немає.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
