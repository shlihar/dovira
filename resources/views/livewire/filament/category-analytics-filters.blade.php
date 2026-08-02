<div>
    <section class="fi-section fi-section-has-header">
        <header class="fi-section-header">
            <div class="fi-section-header-text-ctn">
                <h2 class="fi-section-header-heading">Фільтри аналітики</h2>
                <p class="fi-section-header-description">Період впливає на KPI категорії. За замовчуванням: 7 днів.</p>
            </div>
        </header>

        <div class="fi-section-content-ctn">
            <div class="fi-sc fi-sc-has-gap fi-grid md:fi-grid-cols fi-section-content" style="--cols-default: repeat(1, minmax(0, 1fr)); --cols-md: repeat(3, minmax(0, 1fr));">
                <div class="fi-grid-col" style="--col-span-default: span 1 / span 1;">
                    <div data-field-wrapper class="fi-fo-field fi-fo-select-wrp">
                        <div class="fi-fo-field-label-col">
                            <div class="fi-fo-field-label-ctn">
                                <label class="fi-fo-field-label" for="category-analytics-period">
                                    <span class="fi-fo-field-label-content">Період</span>
                                </label>
                            </div>
                        </div>
                        <div class="fi-fo-field-content-col">
                            <div class="fi-input-wrp fi-fo-select fi-fo-select-native">
                                <div class="fi-input-wrp-content-ctn">
                                    <select id="category-analytics-period" class="fi-select-input" wire:model.live="period">
                                        <option value="today">Сьогодні</option>
                                        <option value="last_7">Останні 7 днів</option>
                                        <option value="last_30">Останні 30 днів</option>
                                        <option value="last_90">Останні 90 днів</option>
                                        <option value="all_time">Весь час</option>
                                        <option value="custom">Кастомний період</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="fi-grid-col" style="--col-span-default: span 1 / span 1;">
                    <div data-field-wrapper class="fi-fo-field">
                        <div class="fi-fo-field-label-col">
                            <div class="fi-fo-field-label-ctn">
                                <label class="fi-fo-field-label" for="category-analytics-from">
                                    <span class="fi-fo-field-label-content">Початкова дата</span>
                                </label>
                            </div>
                        </div>
                        <div class="fi-fo-field-content-col">
                            <div class="fi-input-wrp fi-fo-date-time-picker">
                                <div class="fi-input-wrp-content-ctn">
                                    <input id="category-analytics-from" class="fi-input" type="date" wire:model.live="startDate">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="fi-grid-col" style="--col-span-default: span 1 / span 1;">
                    <div data-field-wrapper class="fi-fo-field">
                        <div class="fi-fo-field-label-col">
                            <div class="fi-fo-field-label-ctn">
                                <label class="fi-fo-field-label" for="category-analytics-to">
                                    <span class="fi-fo-field-label-content">Кінцева дата</span>
                                </label>
                            </div>
                        </div>
                        <div class="fi-fo-field-content-col">
                            <div class="fi-input-wrp fi-fo-date-time-picker">
                                <div class="fi-input-wrp-content-ctn">
                                    <input id="category-analytics-to" class="fi-input" type="date" wire:model.live="endDate">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    @if ($record)
        <div class="space-y-5 pt-1">
            @livewire(
                \App\Filament\Resources\Categories\Widgets\CategoryAnalyticsStatsWidget::class,
                ['record' => $record, 'period' => $period, 'from' => $startDate, 'to' => $endDate],
                key('category-analytics-stats-' . $record->id . '-' . $period . '-' . ($startDate ?? 'null') . '-' . ($endDate ?? 'null'))
            )

            @livewire(
                \App\Filament\Resources\Categories\Widgets\CategoryProfilesTableWidget::class,
                ['record' => $record],
                key('category-profiles-table-' . $record->id)
            )
        </div>
    @endif
</div>
