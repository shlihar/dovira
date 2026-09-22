<?php

namespace App\Support;

class SearchText
{
    /**
     * @return array<int, string>
     */
    public static function tokens(string $query, int $limit = 8): array
    {
        $normalized = self::normalize($query);
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/[^\p{L}\p{N}]+/u', $normalized) ?: [];

        return collect($parts)
            ->map(fn ($part) => trim((string) $part))
            ->filter(fn ($part) => mb_strlen($part) >= 2)
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    public static function normalize(string $query): string
    {
        $query = mb_strtolower(trim($query));
        $query = preg_replace('/\s+/u', ' ', $query) ?? $query;

        return trim($query);
    }

    /**
     * @return array<int, string>
     */
    public static function caseVariants(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        return collect([
            $value,
            mb_strtolower($value),
            mb_convert_case($value, MB_CASE_TITLE, 'UTF-8'),
            mb_strtoupper($value),
        ])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
