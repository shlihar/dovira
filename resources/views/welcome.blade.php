<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DOVIRA — цифрова платформа чесних відгуків</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased text-[var(--color-text)] bg-muted">
    <div class="min-h-screen bg-section">
        <header class="bg-hero text-white relative overflow-hidden">
            <div class="floating-shape"></div>
            <div class="max-w-6xl mx-auto px-4 lg:px-8 pt-6 pb-12 relative z-10">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="h-10 w-10 rounded-xl bg-white/20 flex items-center justify-center font-semibold">D</div>
                        <span class="text-lg font-semibold">DOVIRA</span>
                    </div>
                    <nav class="hidden md:flex items-center gap-6 text-sm font-semibold">
                        <a href="#platform" class="hover:underline">Про платформу</a>
                        <a href="#pro" class="hover:underline">Про PRO</a>
                        <a href="#process" class="hover:underline">Як це працює</a>
                        <a href="{{ route('catalog') }}" class="hover:underline">Каталог</a>
                    </nav>
                    <div class="flex items-center gap-3">
                        <a href="{{ route('login') }}" class="px-4 py-2 rounded-full bg-white/10 border border-white/20 hover:bg-white/20 transition">Увійти</a>
                        <a href="{{ route('register') }}" class="px-4 py-2 rounded-full bg-white text-primary font-semibold shadow-[0_12px_32px_rgba(31,104,255,0.35)] hover:-translate-y-0.5 transition">Зареєструватися</a>
                    </div>
                </div>

                <div class="grid lg:grid-cols-2 gap-12 items-center mt-12">
                    <div class="space-y-6">
                        <span class="px-3 py-1 rounded-full bg-white/15 text-sm inline-block">Репутація — це актив</span>
                        <h1 class="text-5xl font-bold leading-tight">Цифрова платформа чесних відгуків</h1>
                        <p class="text-lg text-white/90 max-w-xl">Публікація відгуків, перевірка досвіду, врегулювання кейсів та формування об’єктивної репутації компаній і спеціалістів.</p>
                        <div class="flex flex-wrap gap-3">
                            <a href="{{ route('register') }}" class="px-6 py-3 rounded-full bg-white text-primary font-semibold shadow-[0_12px_32px_rgba(31,104,255,0.35)] hover:-translate-y-0.5 transition">Зареєструватися</a>
                            <a href="{{ route('catalog') }}" class="px-6 py-3 rounded-full border border-white/30 bg-white/10 font-semibold hover:bg-white/20 transition">Каталог профілів</a>
                        </div>
                    </div>
                    <div class="relative">
                        <div class="absolute -left-10 -top-10 h-28 w-28 rounded-full bg-white/10 blur-2xl"></div>
                        <div class="absolute -right-10 bottom-4 h-24 w-24 rounded-full bg-accent blur-2xl opacity-40"></div>
                        <div class="glass card-soft rounded-3xl p-6 relative">
                            <div class="flex items-center justify-between mb-4">
                                <div class="text-sm font-semibold text-primary">Панель відгуків</div>
                                <span class="px-3 py-1 rounded-full bg-primary text-white text-xs">На перевірці</span>
                            </div>
                            <div class="space-y-3">
                                <div class="p-4 rounded-2xl bg-white shadow-sm border border-border">
                                    <div class="flex items-center justify-between mb-1">
                                        <span class="text-sm font-semibold text-primary-dark">Статус кейсу</span>
                                        <span class="text-xs px-2 py-1 rounded-full bg-green-100 text-green-700">Вирішено</span>
                                    </div>
                                    <p class="text-sm text-slate-700">“Підтверджений профіль та офіційна відповідь на відгук.”</p>
                                </div>
                                <div class="grid grid-cols-2 gap-3 text-sm">
                                    <div class="p-4 rounded-2xl bg-white border border-border shadow-sm">
                                        <div class="text-xs text-slate-500">Відгуків</div>
                                        <div class="text-2xl font-bold text-primary">128</div>
                                    </div>
                                    <div class="p-4 rounded-2xl bg-white border border-border shadow-sm">
                                        <div class="text-xs text-slate-500">Кейси</div>
                                        <div class="text-2xl font-bold text-primary">24</div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <div class="h-12 w-12 rounded-2xl bg-primary/15 border border-primary/30 flex items-center justify-center text-primary font-bold">PRO</div>
                                    <div class="text-sm">
                                        <div class="font-semibold text-primary-dark">PRO-акаунт активний</div>
                                        <div class="text-slate-600">Розширений функціонал для управління репутацією.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="max-w-6xl mx-auto px-4 lg:px-8 py-12 space-y-12">
            <section id="platform" class="space-y-6">
                <div>
                    <h2 class="text-3xl font-bold text-primary-dark">Про DOVIRA</h2>
                    <p class="text-slate-700">Незалежна платформа чесних відгуків про компанії та спеціалістів.</p>
                </div>
                <div class="grid md:grid-cols-4 gap-4">
                    @foreach ([
                        ['Публікація відгуків', 'Можливість залишати та читати досвід інших.'],
                        ['Перевірка досвіду', 'Модерація та верифікація доказів.'],
                        ['Врегулювання кейсів', 'Прозорий процес спорів та відповідей.'],
                        ['Формування репутації', 'Вимірювані показники довіри.'],
                    ] as [$title, $text])
                        <div class="rounded-2xl bg-white border border-border card-soft p-4 shadow-[0_12px_40px_rgba(16,62,156,0.12)]">
                            <div class="h-10 w-10 rounded-xl bg-primary/10 text-primary font-semibold flex items-center justify-center mb-3">✓</div>
                            <div class="font-semibold mb-1">{{ $title }}</div>
                            <p class="text-sm text-slate-600">{{ $text }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="grid md:grid-cols-2 gap-6 items-start">
                <div class="rounded-3xl bg-white border border-border card-soft p-6 shadow-[0_12px_40px_rgba(16,62,156,0.08)]">
                    <h3 class="text-xl font-bold text-primary-dark mb-4">Проблеми ринку</h3>
                    <div class="space-y-3">
                        @foreach (['Фейкові відгуки', 'Дезорієнтація користувачів', 'Відсутність врегулювання'] as $item)
                            <div class="p-4 rounded-2xl border border-border bg-muted/60 shadow-sm">
                                <div class="font-semibold">{{ $item }}</div>
                                <p class="text-sm text-slate-600">Користувачам важко відрізнити правду від фейку.</p>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="rounded-3xl bg-white border border-border card-soft p-6 shadow-[0_12px_40px_rgba(16,62,156,0.08)]">
                    <h3 class="text-xl font-bold text-primary-dark mb-4">Рішення DOVIRA</h3>
                    <div class="space-y-3">
                        @foreach (['Прозора система відгуків і перевірок', 'Офіційні відповіді від підтверджених профілів', 'Кабінет кейсів і статусів'] as $item)
                            <div class="p-4 rounded-2xl border border-border bg-primary/5 shadow-sm">
                                <div class="font-semibold text-primary-dark">{{ $item }}</div>
                                <p class="text-sm text-slate-600">Все фіксується, має статус і історію дій.</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section id="pro" class="space-y-6">
                <div>
                    <h3 class="text-2xl font-bold text-primary-dark">Переваги PRO-акаунту</h3>
                    <p class="text-slate-700">Розширений функціонал для брендів та спеціалістів.</p>
                </div>
                <div class="grid md:grid-cols-3 gap-4">
                    @foreach ([
                        'Підтверджений профіль',
                        'Офіційні відповіді',
                        'Кабінет кейсів',
                        'Система перевірок',
                        'Аналітика',
                        'Документи та докази',
                        'Команда доступу',
                        'Публічні позиції',
                        'Вирішені кейси'
                    ] as $item)
                        <div class="rounded-2xl bg-white border border-border p-4 shadow-[0_8px_24px_rgba(16,62,156,0.08)] flex items-start gap-3">
                            <div class="h-10 w-10 rounded-xl bg-primary/10 text-primary font-semibold flex items-center justify-center">★</div>
                            <div>
                                <div class="font-semibold">{{ $item }}</div>
                                <p class="text-sm text-slate-600">Управляйте репутацією професійно.</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="grid md:grid-cols-2 gap-6 items-center">
                <div class="rounded-3xl bg-white border border-border card-soft p-6 shadow-[0_12px_40px_rgba(16,62,156,0.08)]">
                    <h3 class="text-xl font-bold text-primary-dark mb-2">Модель монетизації</h3>
                    <p class="text-slate-700 mb-4">Єдине джерело доходу — PRO-акаунт за підпискою.</p>
                    <div class="p-4 rounded-2xl bg-muted border border-border flex items-center justify-between shadow-sm">
                        <div>
                            <div class="font-semibold">Професійний кабінет</div>
                            <div class="text-sm text-slate-600">Розширені можливості для компаній і спеціалістів.</div>
                        </div>
                        <span class="px-3 py-1 rounded-full bg-primary text-white text-xs">PRO</span>
                    </div>
                </div>
                <div id="process" class="rounded-3xl bg-white border border-border card-soft p-6 shadow-[0_12px_40px_rgba(16,62,156,0.08)]">
                    <h3 class="text-xl font-bold text-primary-dark mb-2">Процес підтвердження профілю</h3>
                    <div class="grid grid-cols-4 gap-3 text-center text-sm font-semibold mb-4">
                        @foreach (['1. Реєстрація акаунта', '2. Вибір профілю', '3. Підтвердження даних', '4. Верифікація'] as $step)
                            <div class="p-3 rounded-2xl border border-border bg-muted/60 shadow-sm">{{ $step }}</div>
                        @endforeach
                    </div>
                    <div class="px-4 py-3 rounded-2xl bg-green-50 text-green-700 font-semibold border border-green-200 text-center shadow-sm">
                        Статус: На перевірці
                    </div>
                </div>
            </section>
        </main>

        <section id="cta" class="bg-hero text-white relative overflow-hidden">
            <div class="floating-shape"></div>
            <div class="max-w-6xl mx-auto px-4 lg:px-8 py-12 flex flex-col md:flex-row items-center justify-between gap-6 relative z-10">
                <div>
                    <h3 class="text-3xl font-bold mb-2">Приєднуйтесь до DOVIRA</h3>
                    <p class="text-white/90">Запускайте перевірені відгуки та керуйте репутацією прозоро.</p>
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('register') }}" class="px-6 py-3 rounded-full bg-white text-primary font-semibold shadow-[0_12px_32px_rgba(31,104,255,0.35)] hover:-translate-y-0.5 transition">Зареєструватися</a>
                    <a href="{{ route('catalog') }}" class="px-6 py-3 rounded-full border border-white/30 bg-white/10 font-semibold hover:bg-white/20 transition">Каталог</a>
                </div>
            </div>
        </section>

        <footer class="bg-[#0c3f9e] text-white">
            <div class="max-w-6xl mx-auto px-4 lg:px-8 py-6 flex flex-col md:flex-row items-center justify-between gap-4">
                <div class="text-sm text-white/80">© 2024 DOVIRA. Всі права захищені.</div>
                <div class="flex gap-4 text-sm">
                    <a href="#" class="hover:underline">Про нас</a>
                    <a href="#" class="hover:underline">FAQ</a>
                    <a href="#" class="hover:underline">Блог</a>
                    <a href="#" class="hover:underline">Контакти</a>
                    <a href="#" class="hover:underline">Політика конфіденційності</a>
                </div>
            </div>
        </footer>
    </div>
</body>
</html>
