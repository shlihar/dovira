<?php

namespace App\Console\Commands;

use App\Models\SitePageEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ShowSiteStats extends Command
{
    protected $signature = 'dovira:stats {--days=7 : Період у днях}';

    protected $description = 'Зведення поведінки користувачів: трафік, пошук, фільтри, воронка відгуків, CTA.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $this->info("=== Статистика за останні {$days} дн. (з {$since->format('Y-m-d H:i')}) ===");

        /* ---- Трафік ---- */
        $views = SitePageEvent::query()
            ->where('event_type', 'site_page_view')
            ->where('created_at', '>=', $since);

        $totalViews = (clone $views)->count();
        $uniqueVisitors = (clone $views)->distinct('visitor_id')->count('visitor_id');

        $this->newLine();
        $this->line("<options=bold>Трафік:</> {$totalViews} переглядів, {$uniqueVisitors} унікальних відвідувачів");

        $this->table(['Сторінка', 'Перегляди'], (clone $views)
            ->select('page_path', DB::raw('count(*) as c'))
            ->groupBy('page_path')->orderByDesc('c')->limit(10)
            ->get()->map(fn ($row) => [(string) $row->page_path, $row->c])->all());

        $this->table(['Джерело', 'Перегляди'], (clone $views)
            ->select('source', DB::raw('count(*) as c'))
            ->groupBy('source')->orderByDesc('c')->limit(8)
            ->get()->map(fn ($row) => [(string) ($row->source ?: '—'), $row->c])->all());

        $this->table(['Пристрій', 'Перегляди'], (clone $views)
            ->select('device_type', DB::raw('count(*) as c'))
            ->groupBy('device_type')->orderByDesc('c')
            ->get()->map(fn ($row) => [(string) ($row->device_type ?: '—'), $row->c])->all());

        /* ---- Пошук ---- */
        $topQueries = SitePageEvent::query()
            ->where('event_type', 'search_query')
            ->where('created_at', '>=', $since)
            ->select('event_label', DB::raw('count(*) as c'))
            ->groupBy('event_label')->orderByDesc('c')->limit(15)
            ->get();

        $this->newLine();
        $this->line('<options=bold>Топ пошукових запитів</> (що люди шукають — підказка для категорій і контенту):');
        $this->table(['Запит', 'Разів'], $topQueries->map(fn ($row) => [(string) $row->event_label, $row->c])->all());

        /* ---- Фільтри й сортування ---- */
        $this->line('<options=bold>Використання фільтрів і сортувань:</>');
        $this->table(['Дія', 'Значення', 'Разів'], SitePageEvent::query()
            ->whereIn('event_type', ['catalog_filter_apply', 'catalog_sort_change', 'catalog_load_more'])
            ->where('created_at', '>=', $since)
            ->select('event_type', 'event_label', DB::raw('count(*) as c'))
            ->groupBy('event_type', 'event_label')->orderByDesc('c')->limit(15)
            ->get()->map(fn ($row) => [$row->event_type, (string) ($row->event_label ?: '—'), $row->c])->all());

        /* ---- Воронка відгуків ---- */
        $funnel = SitePageEvent::query()
            ->whereIn('event_type', ['review_popup_open', 'review_submit_success', 'review_submit_error'])
            ->where('created_at', '>=', $since)
            ->select('event_type', DB::raw('count(*) as c'))
            ->groupBy('event_type')
            ->pluck('c', 'event_type');

        $opens = (int) ($funnel['review_popup_open'] ?? 0);
        $successes = (int) ($funnel['review_submit_success'] ?? 0);
        $errors = (int) ($funnel['review_submit_error'] ?? 0);
        $conversion = $opens > 0 ? round($successes / $opens * 100, 1) : 0;

        $this->newLine();
        $this->line('<options=bold>Воронка відгуків:</>');
        $this->line("  Відкрили форму: {$opens}");
        $this->line("  Надіслали відгук: {$successes} (конверсія {$conversion}%)");
        $this->line("  Помилки надсилання: {$errors}".($errors > 0 ? '  ← перевірити, що заважає' : ''));

        /* ---- CTA ---- */
        $this->newLine();
        $this->line('<options=bold>Кліки по CTA:</>');
        $this->table(['CTA', 'Кліки'], SitePageEvent::query()
            ->where('event_type', 'cta_click')
            ->where('created_at', '>=', $since)
            ->select('event_label', DB::raw('count(*) as c'))
            ->groupBy('event_label')->orderByDesc('c')->limit(12)
            ->get()->map(fn ($row) => [(string) ($row->event_label ?: '—'), $row->c])->all());

        /* ---- Воронка оплат ---- */
        $payPageViews = SitePageEvent::query()
            ->where('event_type', 'site_page_view')
            ->where('page_path', '/pay')
            ->where('created_at', '>=', $since)
            ->count();

        $payClicks = SitePageEvent::query()
            ->where('event_type', 'cta_click')
            ->whereIn('event_label', ['pay_monopay'])
            ->where('created_at', '>=', $since)
            ->select('event_label', DB::raw('count(*) as c'))
            ->groupBy('event_label')
            ->pluck('c', 'event_label');

        $orders = \App\Models\PaymentOrder::query()
            ->where('created_at', '>=', $since)
            ->select('method', 'status', DB::raw('count(*) as c'))
            ->groupBy('method', 'status')
            ->get();

        $ordersCreated = fn (string $method) => (int) $orders->where('method', $method)->sum('c');
        $ordersPaid = fn (string $method) => (int) $orders->where('method', $method)->where('status', 'paid')->sum('c');

        $this->newLine();
        $this->line('<options=bold>Воронка оплат:</>');
        $this->line("  Переглядів сторінки /pay: {$payPageViews}");
        $this->table(['Метод', 'Кліків по кнопці', 'Створено інвойсів', 'Оплачено', 'Конверсія клік→оплата'], [
            [
                'monopay',
                (int) ($payClicks['pay_monopay'] ?? 0),
                $ordersCreated('monopay'),
                $ordersPaid('monopay'),
                ($payClicks['pay_monopay'] ?? 0) > 0 ? round($ordersPaid('monopay') / $payClicks['pay_monopay'] * 100, 1) . '%' : '—',
            ],
        ]);

        /* ---- Дії на профілях (існуюча таблиця profile_events) ---- */
        $this->line('<options=bold>Дії на сторінках профілів:</>');
        $this->table(['Подія', 'Разів'], DB::table('profile_events')
            ->where('created_at', '>=', $since)
            ->select('event_type', DB::raw('count(*) as c'))
            ->groupBy('event_type')->orderByDesc('c')->limit(12)
            ->get()->map(fn ($row) => [(string) $row->event_type, $row->c])->all());

        return self::SUCCESS;
    }
}
