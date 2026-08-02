<x-filament-panels::page>
    @php
        $data = $this->currentData;
        $kpi = $data['kpi'] ?? [];
        $charts = $data['charts'] ?? [];
        $tables = $data['tables'] ?? [];

        $sections = [
            'filament.admin.pages.analytics' => 'Огляд',
            'filament.admin.pages.analytics.reviews' => 'Відгуки',
            'filament.admin.pages.analytics.profiles' => 'Профілі',
            'filament.admin.pages.analytics.users' => 'Користувачі',
            'filament.admin.pages.analytics.pro' => 'PRO',
            'filament.admin.pages.analytics.moderation' => 'Модерація',
        ];

        $chartLabels = [
            'new_reviews' => 'Нові відгуки',
            'new_users' => 'Нові користувачі',
            'new_profiles' => 'Нові профілі',
            'review_status' => 'Статуси відгуків',
            'rating_distribution' => 'Рейтинги 1-5',
            'status_ratio' => 'Published / Pending / Rejected',
            'reports_count' => 'Скарги',
            'by_category' => 'Категорії',
            'by_region' => 'Регіони',
            'by_type' => 'Типи профілю',
            'registrations' => 'Реєстрації',
            'active_vs_blocked' => 'Активні / Заблоковані',
            'reviews_per_user' => 'Відгуків на користувача',
            'new_pro' => 'Нові PRO',
            'status_breakdown' => 'Статуси PRO',
            'funnel' => 'Конверсія',
            'new_reports' => 'Нові скарги',
            'resolved_reports' => 'Вирішені скарги',
            'rejection_reasons' => 'Причини відхилення',
        ];

        $tableLabels = [
            'latest_reviews' => 'Останні відгуки',
            'latest_claims' => 'Останні заявки',
            'latest_reports' => 'Останні скарги',
            'profiles_new_reviews' => 'Профілі з новими відгуками',
            'profiles_negative' => 'Профілі з негативними відгуками',
            'most_reported_reviews' => 'Найчастіше оскаржувані',
            'top_by_reviews' => 'Топ за відгуками',
            'top_by_rating' => 'Топ за рейтингом',
            'fastest_growth' => 'Найбільший приріст',
            'most_active' => 'Найактивніші користувачі',
            'new_users' => 'Нові користувачі',
            'new_pro_profiles' => 'Нові PRO-профілі',
            'ending_soon' => 'PRO завершується',
            'pending_claims' => 'Незавершені заявки',
            'profiles_most_reports' => 'Профілі зі скаргами',
            'users_rejected_reviews' => 'Користувачі з відхиленнями',
            'latest_cases' => 'Кейси модерації',
        ];

        $rowsFrom = fn ($rows) => ($rows instanceof \Illuminate\Support\Collection) ? $rows : collect($rows);

        $toPairs = function ($chartData) {
            if (is_array($chartData) && isset($chartData['labels'], $chartData['values'])) {
                $pairs = [];
                foreach ($chartData['labels'] as $i => $label) {
                    $pairs[] = ['label' => $label, 'value' => (int) ($chartData['values'][$i] ?? 0)];
                }
                return $pairs;
            }

            if (is_array($chartData)) {
                return collect($chartData)->map(function ($value, $label) {
                    if (is_object($value) && isset($value->total)) {
                        return ['label' => (string) $label, 'value' => (int) $value->total];
                    }
                    return ['label' => (string) $label, 'value' => (int) (is_numeric($value) ? $value : 0)];
                })->values()->all();
            }

            if ($chartData instanceof \Illuminate\Support\Collection) {
                return $chartData->map(function ($row) {
                    return [
                        'label' => (string) ($row->name ?? $row->status ?? $row->type ?? ('#' . ($row->id ?? '—'))),
                        'value' => (int) ($row->total ?? 0),
                    ];
                })->values()->all();
            }

            return [];
        };
    @endphp

    <div class="space-y-5">
        <div class="rounded-xl border border-gray-200 bg-white p-2 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex flex-wrap gap-1.5">
                @foreach($sections as $routeName => $label)
                    <a
                        href="{{ route($routeName) }}"
                        @class([
                            'rounded-md px-2.5 py-1.5 text-xs font-semibold transition',
                            'bg-blue-600 text-white' => request()->routeIs($routeName),
                            'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800' => !request()->routeIs($routeName),
                        ])
                    >
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-2 flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Фільтри</p>
                <button
                    type="button"
                    wire:click="$set('period','30d'); $set('dateFrom', null); $set('dateTo', null); $set('filterCategoryId', null); $set('filterRegionId', null); $set('filterProfileType', null); $set('filterProfileStatus', null); $set('filterVerified', null); $set('filterPro', null);"
                    class="rounded-md border border-gray-300 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
                >
                    Скинути
                </button>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <select wire:model.live="period" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-800">
                    <option value="today">Сьогодні</option>
                    <option value="7d">7 днів</option>
                    <option value="30d">30 днів</option>
                    <option value="90d">90 днів</option>
                    <option value="custom">Власний період</option>
                </select>

                <input wire:model.live="dateFrom" type="date" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-800" @disabled($this->period !== 'custom') />
                <input wire:model.live="dateTo" type="date" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-800" @disabled($this->period !== 'custom') />
                <div class="rounded-lg bg-blue-50 px-2 py-2 text-xs font-semibold text-blue-700 dark:bg-blue-900/20 dark:text-blue-300">{{ $this->dateRangeLabel }}</div>
            </div>

            <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <select wire:model.live="filterCategoryId" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-800">
                    <option value="">Категорія: усі</option>
                    @foreach($this->categoryOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>

                <select wire:model.live="filterRegionId" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-800">
                    <option value="">Регіон: усі</option>
                    @foreach($this->regionOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>

                <select wire:model.live="filterProfileType" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-800">
                    <option value="">Тип: усі</option>
                    <option value="company">Компанії</option>
                    <option value="specialist">Спеціалісти</option>
                    <option value="service">Сервіси</option>
                </select>

                <select wire:model.live="filterProfileStatus" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-800">
                    <option value="">Статус: усі</option>
                    <option value="active">Активний</option>
                    <option value="hidden">Прихований</option>
                    <option value="pending">На модерації</option>
                </select>

                <select wire:model.live="filterVerified" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-800">
                    <option value="">Verified: усі</option>
                    <option value="1">Так</option>
                    <option value="0">Ні</option>
                </select>

                <select wire:model.live="filterPro" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-800">
                    <option value="">PRO: усі</option>
                    <option value="1">Так</option>
                    <option value="0">Ні</option>
                </select>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach($kpi as $k => $entry)
                @php
                    $card = is_array($entry) && array_key_exists('label', $entry)
                        ? $entry
                        : [
                            'label' => is_string($k) ? $k : 'Показник',
                            'value' => is_scalar($entry) ? $entry : 0,
                            'delta' => 0,
                            'percent' => 0,
                            'trend' => 'flat',
                            'tone' => 'neutral',
                        ];
                    $tone = $card['tone'] ?? 'neutral';
                    $trend = $card['trend'] ?? 'flat';
                    $isUp = $trend === 'up';
                    $isDown = $trend === 'down';
                @endphp
                <div class="rounded-2xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <p class="truncate text-[11px] font-medium text-gray-500">{{ $card['label'] ?? '—' }}</p>
                    <p class="mt-1 text-2xl font-semibold leading-tight text-gray-900 dark:text-white">{{ number_format((float) ($card['value'] ?? 0), 0, '.', ' ') }}</p>
                    <div class="mt-2 flex items-center gap-1.5 text-xs font-semibold">
                        <span @class([
                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5',
                            'bg-emerald-50 text-emerald-700' => $isUp,
                            'bg-rose-50 text-rose-700' => $isDown,
                            'bg-slate-100 text-slate-600' => !$isUp && !$isDown,
                        ])>
                            @if($isUp) ↗ @elseif($isDown) ↘ @else → @endif
                            {{ abs((float) ($card['percent'] ?? 0)) }}%
                        </span>
                        <span class="text-gray-500">до попереднього періоду</span>
                    </div>
                    <div class="mt-2 h-1.5 rounded-full bg-gray-100 dark:bg-gray-800">
                        <div
                            @class([
                                'h-1.5 rounded-full',
                                'bg-emerald-500' => $isUp,
                                'bg-rose-500' => $isDown,
                                'bg-blue-500' => !$isUp && !$isDown && $tone !== 'danger' && $tone !== 'warning',
                                'bg-amber-500' => $tone === 'warning',
                                'bg-red-500' => $tone === 'danger',
                            ])
                            style="width: {{ min(max(abs((float) ($card['percent'] ?? 0)), 8), 100) }}%"
                        ></div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            @php
                $mainCharts = [
                    ['left' => 'new_reviews', 'right' => 'new_users', 'title' => 'Нові відгуки та нові користувачі'],
                    ['left' => 'new_profiles', 'right' => 'new_pro', 'title' => 'Нові профілі та нові PRO-підключення'],
                ];
            @endphp
            @foreach($mainCharts as $meta)
                @php
                    $left = $charts[$meta['left']] ?? ['labels' => [], 'values' => []];
                    $right = $charts[$meta['right']] ?? ['labels' => [], 'values' => []];
                    $labels = $left['labels'] ?? [];
                    $leftValues = $left['values'] ?? [];
                    $rightValues = $right['values'] ?? [];
                    $rows = collect($labels)->map(function ($label, $i) use ($leftValues, $rightValues) {
                        return ['label' => $label, 'a' => (int) ($leftValues[$i] ?? 0), 'b' => (int) ($rightValues[$i] ?? 0)];
                    })->values();
                    $max = max($rows->pluck('a')->max() ?? 0, $rows->pluck('b')->max() ?? 0, 1);
                @endphp
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <p class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">{{ $meta['title'] }}</p>
                    <div class="space-y-2">
                        @forelse($rows as $row)
                            <div class="grid grid-cols-[52px_1fr_1fr_36px_36px] items-center gap-2">
                                <div class="text-[11px] font-medium text-gray-500">{{ $row['label'] }}</div>
                                <div class="h-1.5 rounded-full bg-blue-100">
                                    <div class="h-1.5 rounded-full bg-blue-500" style="width: {{ ($row['a'] / $max) * 100 }}%"></div>
                                </div>
                                <div class="h-1.5 rounded-full bg-emerald-100">
                                    <div class="h-1.5 rounded-full bg-emerald-500" style="width: {{ ($row['b'] / $max) * 100 }}%"></div>
                                </div>
                                <div class="text-right text-[11px] font-semibold text-blue-700">{{ $row['a'] }}</div>
                                <div class="text-right text-[11px] font-semibold text-emerald-700">{{ $row['b'] }}</div>
                            </div>
                        @empty
                            <p class="text-xs text-gray-500">Немає даних</p>
                        @endforelse
                    </div>
                    <div class="mt-3 flex flex-wrap gap-3 text-[11px] font-medium text-gray-500">
                        <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-blue-500"></span> {{ $chartLabels[$meta['left']] ?? $meta['left'] }}</span>
                        <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-emerald-500"></span> {{ $chartLabels[$meta['right']] ?? $meta['right'] }}</span>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            @foreach(['latest_reviews', 'latest_claims'] as $tableName)
                @php $rows = $tables[$tableName] ?? collect(); @endphp
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <div class="border-b border-gray-100 px-3 py-2 dark:border-gray-800">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $tableLabels[$tableName] ?? str($tableName)->replace('_', ' ')->title() }}</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-50 dark:bg-gray-800/60">
                                <tr>
                                    @if($tableName === 'latest_reviews')
                                        <th class="px-3 py-2 text-left font-semibold text-gray-500">Автор</th>
                                        <th class="px-3 py-2 text-left font-semibold text-gray-500">Профіль</th>
                                        <th class="px-3 py-2 text-left font-semibold text-gray-500">Рейтинг</th>
                                        <th class="px-3 py-2 text-left font-semibold text-gray-500">Статус</th>
                                    @else
                                        <th class="px-3 py-2 text-left font-semibold text-gray-500">Хто подав</th>
                                        <th class="px-3 py-2 text-left font-semibold text-gray-500">Профіль</th>
                                        <th class="px-3 py-2 text-left font-semibold text-gray-500">Тип заявки</th>
                                        <th class="px-3 py-2 text-left font-semibold text-gray-500">Статус</th>
                                    @endif
                                    <th class="px-3 py-2 text-left font-semibold text-gray-500">Дата</th>
                                    <th class="px-3 py-2 text-right font-semibold text-gray-500">Дії</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($rowsFrom($rows)->take(10) as $row)
                                    <tr class="border-t border-gray-100 dark:border-gray-800">
                                        @if($tableName === 'latest_reviews')
                                            <td class="px-3 py-2 font-semibold text-gray-900 dark:text-white">{{ $row->author_name }}</td>
                                            <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ $row->profile?->name ?? '—' }}</td>
                                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $row->rating }}</td>
                                            <td class="px-3 py-2"><span class="rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-700">{{ $row->status }}</span></td>
                                            <td class="whitespace-nowrap px-3 py-2 text-gray-500">{{ optional($row->created_at)->format('d.m.Y H:i') }}</td>
                                            <td class="px-3 py-2 text-right">
                                                <a href="{{ route('filament.admin.resources.profile-reviews.edit', ['record' => $row]) }}" class="text-blue-600 hover:underline">Модерувати</a>
                                            </td>
                                        @else
                                            <td class="px-3 py-2 font-semibold text-gray-900 dark:text-white">{{ $row['applicant'] ?? '—' }}</td>
                                            <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ $row['profile'] ?? '—' }}</td>
                                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $row['type'] ?? '—' }}</td>
                                            <td class="px-3 py-2"><span class="rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-700">{{ $row['status'] ?? '—' }}</span></td>
                                            <td class="whitespace-nowrap px-3 py-2 text-gray-500">{{ isset($row['created_at']) ? \Illuminate\Support\Carbon::parse($row['created_at'])->format('d.m.Y H:i') : '—' }}</td>
                                            <td class="px-3 py-2 text-right text-gray-400">—</td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-3 py-3 text-center text-gray-500">Немає записів</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
