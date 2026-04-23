<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Каталог профілів</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-muted text-[var(--color-text)]">
    <div class="max-w-6xl mx-auto px-4 lg:px-8 py-10 space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold text-primary-dark">Каталог профілів</h1>
                <p class="text-slate-600">Фільтруйте за областю або шукайте за назвою профілю.</p>
            </div>
            <a href="{{ url('/') }}" class="text-sm text-primary hover:underline">Головна</a>
        </div>

        <form method="GET" class="bg-white border border-border rounded-2xl p-4 card-soft flex flex-col md:flex-row gap-4 items-start md:items-end">
            <div class="flex-1">
                <label class="text-sm text-slate-600 block mb-1">Пошук (назва профілю або ID)</label>
                <input type="text" name="q" value="{{ $search }}" class="w-full rounded-xl border border-border px-4 py-2" placeholder="Введіть запит">
            </div>
            <div>
                <label class="text-sm text-slate-600 block mb-1">Область</label>
                <select name="region" class="rounded-xl border border-border px-4 py-2">
                    <option value="">Усі області</option>
                    @foreach($regions as $region)
                        <option value="{{ $region->slug }}" @selected($regionSlug === $region->slug)>{{ $region->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-4 py-2 rounded-xl bg-primary text-white font-semibold shadow-lg">Застосувати</button>
        </form>

        <div class="grid md:grid-cols-3 gap-3">
            @foreach($regions as $region)
                <a href="{{ route('catalog', ['region' => $region->slug]) }}" class="bg-white border border-border rounded-2xl p-3 card-soft hover:-translate-y-0.5 transition">
                    <div class="flex items-center justify-between">
                        <span class="font-semibold">{{ $region->name }}</span>
                        <span class="text-xs px-2 py-1 rounded-full bg-primary/10 text-primary font-semibold">
                            {{ $region->lawyers_count }}
                        </span>
                    </div>
                </a>
            @endforeach
        </div>

        <div class="bg-white border border-border rounded-2xl p-4 card-soft">
            <h2 class="text-xl font-bold text-primary-dark mb-3">Профілі</h2>
            @if($lawyers->isEmpty())
                <p class="text-slate-600">Немає результатів.</p>
            @else
                <div class="space-y-3">
                    @foreach($lawyers as $lawyer)
                        <div class="p-3 rounded-xl border border-border bg-muted/50 flex items-center justify-between">
                            <div>
                                <a href="{{ route('lawyers.show', $lawyer) }}" class="text-primary font-semibold hover:underline">{{ $lawyer->full_name }}</a>
                                <div class="text-xs text-slate-500">ID профілю: {{ $lawyer->certificate_number }} | {{ $lawyer->region->name ?? '—' }}</div>
                            </div>
                            @if($lawyer->is_suspended)
                                <span class="text-xs px-2 py-1 rounded-full bg-red-100 text-red-700">Зупинено</span>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="mt-4">
                    {{ $lawyers->links() }}
                </div>
            @endif
        </div>
    </div>
</body>
</html>
