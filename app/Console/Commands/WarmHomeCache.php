<?php

namespace App\Console\Commands;

use App\Services\HomePageDataService;
use Illuminate\Console\Command;

/**
 * Прогріває кеш головної. Холодна перебудова важких секцій (лідерборд,
 * агрегати відгуків по 500k+ рядків) займає ~8 с — без прогріву цей час
 * платить перший відвідувач після кожного спливання TTL (6 год) чи cache:clear.
 */
class WarmHomeCache extends Command
{
    protected $signature = 'dovira:warm-home';

    protected $description = 'Прогріває кеш головної сторінки, щоб холодна перебудова не била по відвідувачу.';

    public function handle(HomePageDataService $home): int
    {
        $start = microtime(true);
        $home->refresh();
        $this->info('Home cache warmed in ' . round((microtime(true) - $start) * 1000) . ' ms');

        return self::SUCCESS;
    }
}
