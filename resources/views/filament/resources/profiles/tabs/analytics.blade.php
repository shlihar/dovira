@if (! isset($record) || ! $record?->id)
    <x-filament::section>
        <x-slot name="heading">Аналітика профілю</x-slot>
        <div class="text-sm opacity-70">
            Аналітика зʼявиться після створення профілю.
        </div>
    </x-filament::section>
@else
    <div class="space-y-5">
        @livewire(\App\Livewire\Filament\ProfileAnalyticsFilters::class, ['recordId' => $record->id], key('profile-analytics-filters-'.$record->id))
    </div>
@endif
