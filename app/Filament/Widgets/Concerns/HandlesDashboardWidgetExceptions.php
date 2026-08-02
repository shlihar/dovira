<?php

namespace App\Filament\Widgets\Concerns;

use Illuminate\Support\Facades\Log;
use Throwable;

trait HandlesDashboardWidgetExceptions
{
    protected function reportDashboardWidgetException(string $context, Throwable $exception): void
    {
        Log::warning('Admin dashboard widget fallback activated.', [
            'widget' => static::class,
            'context' => $context,
            'message' => $exception->getMessage(),
        ]);
    }
}
