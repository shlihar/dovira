<x-filament-widgets::widget>
    <x-filament::section heading="Швидкі дії" description="Операції, які адміністратор виконує найчастіше">
        <div class="flex flex-wrap gap-2">
            <x-filament::button tag="a" icon="heroicon-m-plus" href="{{ route('filament.admin.resources.profiles.create') }}">Додати профіль</x-filament::button>
            <x-filament::button tag="a" icon="heroicon-m-squares-plus" color="gray" href="{{ route('filament.admin.resources.categories.create') }}">Додати категорію</x-filament::button>
            <x-filament::button tag="a" icon="heroicon-m-shield-exclamation" color="warning" href="{{ route('filament.admin.resources.reviews.index') }}">Модерація відгуків</x-filament::button>
            <x-filament::button tag="a" icon="heroicon-m-clipboard-document-list" color="info" href="{{ route('filament.admin.resources.profile-claims.index') }}">Заявки на профілі</x-filament::button>
            <x-filament::button tag="a" icon="heroicon-m-arrow-up-tray" color="success" href="{{ route('filament.admin.resources.top20-import-batches.index') }}">Імпорт TOP20</x-filament::button>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
