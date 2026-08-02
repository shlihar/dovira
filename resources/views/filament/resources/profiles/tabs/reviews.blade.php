@if (! isset($record) || ! $record?->id)
    <x-filament::section>
        <x-slot name="heading">Відгуки профілю</x-slot>
        <div class="text-sm opacity-70">
            Відгуки зʼявляться після створення профілю.
        </div>
    </x-filament::section>
@else
    @php
        $reviews = $record->reviews()->latest()->limit(25)->get();
    @endphp

    <x-filament::section>
        <x-slot name="heading">Відгуки профілю</x-slot>
        <x-slot name="description">Останні відгуки по цьому профілю.</x-slot>

        @if($reviews->isEmpty())
            <div class="text-sm opacity-70">Поки немає відгуків.</div>
        @else
            <div class="overflow-x-auto">
                <table class="fi-ta-table min-w-full divide-y divide-gray-200 dark:divide-white/10">
                    <thead>
                    <tr>
                        <th class="px-3 py-2 text-left text-xs">Автор</th>
                        <th class="px-3 py-2 text-left text-xs">Рейтинг</th>
                        <th class="px-3 py-2 text-left text-xs">Статус</th>
                        <th class="px-3 py-2 text-left text-xs">Дата</th>
                        <th class="px-3 py-2 text-right text-xs">Дії</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach($reviews as $review)
                        @php
                            $statusColor = match($review->status){
                                'published' => 'success',
                                'pending', 'under_review' => 'warning',
                                'rejected', 'hidden' => 'danger',
                                default => 'gray',
                            };
                        @endphp
                        <tr>
                            <td class="px-3 py-2 text-sm">{{ $review->author_name ?: '—' }}</td>
                            <td class="px-3 py-2 text-sm">{{ number_format((float)$review->rating, 1) }}</td>
                            <td class="px-3 py-2 text-sm">
                                <x-filament::badge :color="$statusColor">{{ $review->status }}</x-filament::badge>
                            </td>
                            <td class="px-3 py-2 text-sm">{{ optional($review->created_at)->format('d.m.Y H:i') }}</td>
                            <td class="px-3 py-2 text-sm text-right">
                                <div class="inline-flex gap-2">
                                    <x-filament::link :href="route('filament.admin.resources.reviews.view', ['record' => $review])" icon="heroicon-o-eye">Переглянути</x-filament::link>
                                    <x-filament::link :href="route('filament.admin.resources.reviews.edit', ['record' => $review])" icon="heroicon-o-pencil-square">Редагувати</x-filament::link>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
@endif
