# Filament + Brisk: правила для цього проєкту

## 1) Не використовуємо кастомний HTML там, де є нативний Filament
- Для фільтрів/форм: тільки `Schema` + `Forms` компоненти.
- Для KPI: тільки `StatsOverviewWidget`.
- Для графіків: тільки `ChartWidget`.

## 2) Один спільний schema для однакових блоків
- Якщо фільтри однакові на Dashboard і в інших місцях, використовуємо один helper:
  - `App\Filament\Support\AnalyticsFiltersSchema`.

## 3) Єдиний формат періодів
- `today`, `last_7`, `last_30`, `last_90`, `all_time`, `custom`.
- Будь-який новий графік/віджет має читати ці самі значення.

## 4) Вигляд “як у Dashboard”
- Розкладка фільтрів: `Section` + `->columns(['default' => 1, 'md' => 3])`.
- KPI картки: `Stat::make(...)->description(...)->descriptionIcon(...)->color(...)->chart(...)`.
- Графік: `ChartWidget` з `line`, легендою і `beginAtZero`.

## 5) Без “костилів”
- Не дублюємо стилі через довільні CSS-класи, якщо це можна вирішити через Filament-компоненти.
- Кастомний Blade допустимий тільки як обгортка для `@livewire(...)` або для простого layout-контейнера.

## 6) Перед здачею
- `php artisan optimize:clear`
- перевірка сторінки Dashboard і відповідного блоку в ресурсі, щоб компоненти були однакові за структурою.

