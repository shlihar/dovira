<x-filament-widgets::widget>
    <x-filament::section>
        <div class="fi-section-header-text-ctn" style="margin-bottom:.75rem;">
            <h3 class="fi-section-header-heading">Фільтри аналітики</h3>
            <p class="fi-section-header-description">Період впливає на KPI та графік профілю.</p>
        </div>

        <form method="GET" class="grid gap-4 md:grid-cols-3">
            <input type="hidden" name="page" value="{{ request('page') }}">
            <div>
                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3" for="analytics_period">
                    <span class="text-sm font-medium">Період</span>
                </label>
                <select id="analytics_period" name="analytics_period" class="fi-input block w-full rounded-xl border border-gray-300/60 bg-white/70 px-3 py-2.5 text-sm dark:border-white/10 dark:bg-white/5" onchange="this.form.submit()">
                    <option value="today" @selected($period === 'today')>Сьогодні</option>
                    <option value="last_7" @selected($period === 'last_7')>Останні 7 днів</option>
                    <option value="last_30" @selected($period === 'last_30')>Останні 30 днів</option>
                    <option value="last_90" @selected($period === 'last_90')>Останні 90 днів</option>
                    <option value="all_time" @selected($period === 'all_time')>Весь час</option>
                    <option value="custom" @selected($period === 'custom')>Кастомний</option>
                </select>
            </div>

            <div>
                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3" for="analytics_from">
                    <span class="text-sm font-medium">Початкова дата</span>
                </label>
                <input id="analytics_from" type="date" name="analytics_from" value="{{ $from }}" class="fi-input block w-full rounded-xl border border-gray-300/60 bg-white/70 px-3 py-2.5 text-sm dark:border-white/10 dark:bg-white/5" onchange="if (document.getElementById('analytics_period').value === 'custom') this.form.submit()">
            </div>

            <div>
                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3" for="analytics_to">
                    <span class="text-sm font-medium">Кінцева дата</span>
                </label>
                <input id="analytics_to" type="date" name="analytics_to" value="{{ $to }}" class="fi-input block w-full rounded-xl border border-gray-300/60 bg-white/70 px-3 py-2.5 text-sm dark:border-white/10 dark:bg-white/5" onchange="if (document.getElementById('analytics_period').value === 'custom') this.form.submit()">
            </div>
        </form>
    </x-filament::section>
</x-filament-widgets::widget>
