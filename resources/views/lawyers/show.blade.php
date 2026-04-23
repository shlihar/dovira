<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $lawyer->full_name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-muted text-[var(--color-text)]">
    <div class="max-w-5xl mx-auto px-4 lg:px-8 py-10 space-y-6">
        <a href="{{ route('catalog') }}" class="text-primary text-sm hover:underline">← До каталогу</a>

        <div class="bg-white border border-border rounded-3xl p-6 card-soft">
            <div class="flex flex-col md:flex-row gap-6">
                <div class="flex-1 space-y-2">
                    <h1 class="text-3xl font-bold text-primary-dark">{{ $lawyer->full_name }}</h1>
                    <div class="text-slate-700">Свідоцтво № {{ $lawyer->certificate_number }} @if($lawyer->certificate_issued_at) від {{ $lawyer->certificate_issued_at->format('d.m.Y') }} @endif</div>
                    <div class="text-slate-700">Орган: {{ $lawyer->certificate_issuer ?? '—' }}</div>
                    <div class="text-slate-700">Регіон: {{ $lawyer->region->name ?? 'Не вказано' }}</div>
                    <div class="text-slate-700">Email: {{ $lawyer->email ?? '—' }}</div>
                    <div class="text-slate-700">Номер рішення: {{ $lawyer->decision_number ?? '—' }} @if($lawyer->decision_at) ({{ $lawyer->decision_at->format('d.m.Y') }}) @endif</div>
                    <div class="text-slate-700">Статус: @if($lawyer->is_suspended) <span class="text-red-600 font-semibold">Зупинено</span> @else <span class="text-green-600 font-semibold">Активний</span> @endif</div>
                    @if($lawyer->notes)
                        <div class="text-slate-700">Інші відомості: {{ $lawyer->notes }}</div>
                    @endif
                </div>
                @if($lawyer->photo_url)
                    <div class="w-40 h-40 rounded-2xl overflow-hidden border border-border bg-muted">
                        <img src="{{ $lawyer->photo_url }}" alt="Фото" class="w-full h-full object-cover">
                    </div>
                @endif
            </div>
        </div>

        <div class="bg-white border border-border rounded-3xl p-6 card-soft">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-2xl font-bold text-primary-dark">Відгуки</h2>
                <span class="text-sm text-slate-600">{{ $lawyer->reviews->count() }} відгуків</span>
            </div>
            @if(session('status'))
                <div class="mb-4 px-4 py-3 rounded-xl bg-green-50 text-green-700 border border-green-200">
                    {{ session('status') }}
                </div>
            @endif
            @if($lawyer->reviews->isEmpty())
                <p class="text-slate-600">Поки немає відгуків.</p>
            @else
                <div class="space-y-4">
                    @foreach($lawyer->reviews as $review)
                        <div class="p-4 rounded-2xl border border-border bg-muted/50">
                            <div class="flex items-center justify-between mb-1">
                                <div class="font-semibold">{{ $review->author_name }}</div>
                                <div class="text-sm text-primary">Рейтинг: {{ $review->rating }}/5</div>
                            </div>
                            <p class="text-slate-700">{{ $review->body }}</p>
                            <div class="text-xs text-slate-500 mt-2">{{ $review->created_at->format('d.m.Y') }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="bg-white border border-border rounded-3xl p-6 card-soft">
            <h3 class="text-xl font-bold text-primary-dark mb-3">Залишити відгук</h3>
            @guest
                <p class="text-slate-600">Увійдіть, щоб залишити відгук. <a class="text-primary hover:underline" href="{{ route('login') }}">Увійти</a></p>
            @else
                <form action="{{ route('lawyers.reviews.store', $lawyer) }}" method="POST" class="space-y-3">
                    @csrf
                    <div>
                        <label class="text-sm text-slate-600 block mb-1">Ваше ім’я</label>
                        <input type="text" name="author_name" value="{{ old('author_name', auth()->user()->name) }}" class="w-full rounded-xl border border-border px-4 py-2" required>
                        @error('author_name') <div class="text-red-600 text-sm">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="text-sm text-slate-600 block mb-1">Email (необов’язково)</label>
                        <input type="email" name="author_email" value="{{ old('author_email', auth()->user()->email) }}" class="w-full rounded-xl border border-border px-4 py-2">
                        @error('author_email') <div class="text-red-600 text-sm">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="text-sm text-slate-600 block mb-1">Оцінка</label>
                        <select name="rating" class="rounded-xl border border-border px-4 py-2">
                            @for($i=5; $i>=1; $i--)
                                <option value="{{ $i }}" @selected(old('rating', 5)==$i)>{{ $i }}</option>
                            @endfor
                        </select>
                        @error('rating') <div class="text-red-600 text-sm">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="text-sm text-slate-600 block mb-1">Відгук</label>
                        <textarea name="body" rows="4" class="w-full rounded-xl border border-border px-4 py-2" required>{{ old('body') }}</textarea>
                        @error('body') <div class="text-red-600 text-sm">{{ $message }}</div> @enderror
                    </div>
                    <button type="submit" class="px-6 py-3 rounded-full bg-primary text-white font-semibold shadow-lg hover:-translate-y-0.5 transition">Надіслати</button>
                </form>
            @endguest
        </div>
    </div>
</body>
</html>
