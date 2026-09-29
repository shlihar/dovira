<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Коли контент сайту оновлювався востаннє.
 *
 * Потрібно для `dateModified` у розмітці головної: аудити позначають її
 * відсутність як прогалину, а вигадувати дату не можна. Реальний маркер —
 * останній опублікований відгук: саме він щодня змінює головну (свіжі відгуки,
 * рейтинги, лічильники).
 */
final class SiteFreshness
{
    public static function lastContentUpdate(): CarbonImmutable
    {
        $timestamp = Cache::remember('site:last-content-update:v1', now()->addHour(), function (): ?string {
            return rescue(
                fn (): ?string => DB::table('profile_reviews')
                    ->where('status', 'published')
                    ->max('published_at'),
                null,
                false
            );
        });

        return $timestamp
            ? CarbonImmutable::parse($timestamp)
            : CarbonImmutable::now();
    }
}
