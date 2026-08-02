<?php

namespace App\Support;

class Top20ImportUrl
{
    /**
     * @return array{city_code:?string, city_name:?string, region_name:?string}
     */
    public static function context(string $listingUrl): array
    {
        $cityCode = static::detectCityCode($listingUrl);
        $cityConfig = $cityCode !== null ? config("top20_bulk_import.cities.{$cityCode}") : null;

        return [
            'city_code' => $cityCode,
            'city_name' => is_array($cityConfig) ? (($cityConfig['name'] ?? null) ?: null) : null,
            'region_name' => is_array($cityConfig) ? (($cityConfig['region'] ?? null) ?: null) : null,
        ];
    }

    public static function detectCityCode(string $listingUrl): ?string
    {
        $listingUrl = trim($listingUrl);

        if ($listingUrl === '') {
            return null;
        }

        if (preg_match('~^https?://(?:www\.)?top20\.ua/([a-z]{2})(?:/|$)~i', $listingUrl, $matches) === 1) {
            return strtolower($matches[1]);
        }

        $path = (string) parse_url($listingUrl, PHP_URL_PATH);

        if (preg_match('~^/([a-z]{2})(?:/|$)~i', $path, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }
}
