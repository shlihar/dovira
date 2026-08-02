<?php

namespace App\Jobs;

use App\Services\HomePageDataService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshHomePageCache implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onConnection((string) config('queue.default', 'database'));
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'refresh-home-page-cache';
    }

    public function handle(HomePageDataService $service): void
    {
        $service->refresh();
        $service->forgetLegacyKeys();
    }
}
