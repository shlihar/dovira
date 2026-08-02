<?php

namespace App\Filament\Support;

use Carbon\CarbonImmutable;

class AnalyticsRange
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{start: CarbonImmutable, end: CarbonImmutable, previousStart: CarbonImmutable, previousEnd: CarbonImmutable, days: int}
     */
    public static function fromFilters(array $filters): array
    {
        $end = isset($filters['endDate']) && filled($filters['endDate'])
            ? CarbonImmutable::parse((string) $filters['endDate'])->endOfDay()
            : CarbonImmutable::now()->endOfDay();

        $period = (string) ($filters['period'] ?? 'last_7');

        if ($period === 'custom' && isset($filters['startDate']) && filled($filters['startDate'])) {
            $start = CarbonImmutable::parse((string) $filters['startDate'])->startOfDay();
        } else {
            $days = match ($period) {
                'today' => 1,
                'last_30', '30' => 30,
                'last_90', '90' => 90,
                'all_time' => max(1, CarbonImmutable::now()->startOfDay()->diffInDays($end) + 1),
                default => 7, // last_7 / legacy 7
            };
            $start = $end->subDays($days - 1)->startOfDay();
        }

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
        }

        $days = max(1, $start->diffInDays($end) + 1);
        $previousEnd = $start->subSecond();
        $previousStart = $previousEnd->subDays($days - 1)->startOfDay();

        return [
            'start' => $start,
            'end' => $end,
            'previousStart' => $previousStart,
            'previousEnd' => $previousEnd,
            'days' => $days,
        ];
    }
}
