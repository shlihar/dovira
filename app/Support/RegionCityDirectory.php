<?php

namespace App\Support;

use App\Models\Region;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class RegionCityDirectory
{
    /**
     * Стабільні slug для SEO-URL посадкових сторінок (латиниця, без варіацій
     * транслітерації). Обмежений найбільшими містами, під які реально є попит.
     *
     * @var array<string, string>
     */
    private const CITY_SLUGS = [
        'kyiv' => 'Київ',
        'kharkiv' => 'Харків',
        'odesa' => 'Одеса',
        'dnipro' => 'Дніпро',
        'lviv' => 'Львів',
        'zaporizhzhia' => 'Запоріжжя',
        'vinnytsia' => 'Вінниця',
        'poltava' => 'Полтава',
        'mykolaiv' => 'Миколаїв',
        'cherkasy' => 'Черкаси',
        'chernihiv' => 'Чернігів',
        'chernivtsi' => 'Чернівці',
        'zhytomyr' => 'Житомир',
        'sumy' => 'Суми',
        'rivne' => 'Рівне',
        'ternopil' => 'Тернопіль',
        'khmelnytskyi' => 'Хмельницький',
        'lutsk' => 'Луцьк',
        'uzhhorod' => 'Ужгород',
        'ivano-frankivsk' => 'Івано-Франківськ',
        'kropyvnytskyi' => 'Кропивницький',
        'kryvyi-rih' => 'Кривий Ріг',
    ];

    /**
     * Місцевий відмінок з прийменником для SEO-заголовків посадкових
     * («Адвокати у Вінниці»). Прийменник у складі значення, бо чергується в/у.
     *
     * @var array<string, string>
     */
    private const CITY_LOCATIVES = [
        'Київ' => 'у Києві',
        'Харків' => 'у Харкові',
        'Одеса' => 'в Одесі',
        'Дніпро' => 'у Дніпрі',
        'Львів' => 'у Львові',
        'Запоріжжя' => 'у Запоріжжі',
        'Вінниця' => 'у Вінниці',
        'Полтава' => 'у Полтаві',
        'Миколаїв' => 'у Миколаєві',
        'Черкаси' => 'у Черкасах',
        'Чернігів' => 'у Чернігові',
        'Чернівці' => 'у Чернівцях',
        'Житомир' => 'у Житомирі',
        'Суми' => 'у Сумах',
        'Рівне' => 'у Рівному',
        'Тернопіль' => 'у Тернополі',
        'Хмельницький' => 'у Хмельницькому',
        'Луцьк' => 'у Луцьку',
        'Ужгород' => 'в Ужгороді',
        'Івано-Франківськ' => 'в Івано-Франківську',
        'Кропивницький' => 'у Кропивницькому',
        'Кривий Ріг' => 'у Кривому Розі',
    ];

    /**
     * «у Вінниці» / «в Одесі»; для невідомого міста — нейтральне «у місті X».
     */
    public static function cityLocative(?string $city): ?string
    {
        $canonical = self::canonicalCity($city);
        if ($canonical === null || $canonical === '') {
            return null;
        }

        $normalized = self::normalizeCity($canonical);
        foreach (self::CITY_LOCATIVES as $name => $locative) {
            if (self::normalizeCity($name) === $normalized) {
                return $locative;
            }
        }

        return 'у місті ' . $canonical;
    }

    /**
     * @return array<string, string>
     */
    public static function citySlugMap(): array
    {
        return self::CITY_SLUGS;
    }

    public static function cityFromSlug(?string $slug): ?string
    {
        $slug = Str::lower(trim((string) $slug));

        return self::CITY_SLUGS[$slug] ?? null;
    }

    public static function citySlug(?string $city): ?string
    {
        $canonical = self::canonicalCity($city);
        if ($canonical === null) {
            return null;
        }

        foreach (self::CITY_SLUGS as $slug => $name) {
            if (self::normalizeCity($name) === self::normalizeCity($canonical)) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function registeredCities(): array
    {
        $configuredRegions = (array) config('ukraine_locations.regions', []);
        $aliases = (array) config('ukraine_locations.city_aliases', []);

        return collect($configuredRegions)
            ->flatMap(fn (array $cities): array => $cities)
            ->merge(array_values($aliases))
            ->merge(array_values(self::CITY_SLUGS))
            ->map(fn ($city): string => trim((string) $city))
            ->filter()
            ->unique(fn (string $city): string => self::normalizeCity($city))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function registeredCityOptions(): array
    {
        return collect(self::registeredCities())
            ->mapWithKeys(fn (string $city): array => [$city => $city])
            ->all();
    }

    public static function isRegisteredCity(?string $city): bool
    {
        return self::canonicalRegisteredCity($city) !== null;
    }

    public static function canonicalRegisteredCity(?string $city): ?string
    {
        $canonical = self::canonicalCity($city);
        if ($canonical === null) {
            return null;
        }

        $normalized = self::normalizeCity($canonical);
        foreach (self::registeredCities() as $registeredCity) {
            if (self::normalizeCity($registeredCity) === $normalized) {
                return $registeredCity;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Region>  $regions
     * @return array<int, array{id:int,name:string,cities:array<int,string>}>
     */
    public static function payload(Collection $regions): array
    {
        $configuredRegions = (array) config('ukraine_locations.regions', []);

        return $regions
            ->map(function (Region $region) use ($configuredRegions): array {
                $cities = collect($configuredRegions[$region->name] ?? [])
                    ->map(fn ($city) => trim((string) $city))
                    ->filter()
                    ->unique(fn ($city) => self::normalizeCity($city))
                    ->sort()
                    ->values()
                    ->all();

                return [
                    'id' => (int) $region->id,
                    'name' => $region->name,
                    'cities' => $cities,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Region>  $regions
     */
    public static function inferRegionId(?string $city, Collection $regions): ?int
    {
        $normalizedCity = self::normalizeCity($city);

        if ($normalizedCity === '') {
            return null;
        }

        $configuredRegions = (array) config('ukraine_locations.regions', []);
        $regionIdsByName = $regions
            ->mapWithKeys(fn (Region $region) => [$region->name => (int) $region->id])
            ->all();

        foreach ($configuredRegions as $regionName => $cities) {
            foreach ((array) $cities as $candidateCity) {
                if (self::normalizeCity((string) $candidateCity) !== $normalizedCity) {
                    continue;
                }

                return $regionIdsByName[$regionName] ?? null;
            }
        }

        return null;
    }

    public static function normalizeCity(?string $city): string
    {
        $value = Str::of((string) $city)
            ->lower()
            ->replace(['’', '\'', '`'], '')
            ->replaceMatches('/\b(місто|м)\.?\s+/u', '')
            ->replaceMatches('/,\s*україна$/u', '')
            ->replaceMatches('/\s+україна$/u', '')
            ->replaceMatches('/[\s\-,.]+/u', ' ')
            ->trim();

        return (string) $value;
    }

    public static function canonicalCity(?string $city): ?string
    {
        $normalizedCity = self::normalizeCity($city);

        if ($normalizedCity === '') {
            return null;
        }

        $aliases = (array) config('ukraine_locations.city_aliases', []);

        return $aliases[$normalizedCity] ?? trim((string) $city);
    }

    /**
     * @return array<int, string>
     */
    public static function variantsForCity(?string $city): array
    {
        $canonicalCity = self::canonicalCity($city);

        if ($canonicalCity === null || $canonicalCity === '') {
            return [];
        }

        $aliases = collect((array) config('ukraine_locations.city_aliases', []));
        $variants = $aliases
            ->filter(fn ($target) => self::normalizeCity((string) $target) === self::normalizeCity($canonicalCity))
            ->keys()
            ->map(fn ($alias) => trim((string) $alias))
            ->filter()
            ->values();

        // Канонічна назва — першою: unique() лишає перший збіг, і без цього
        // її витісняв нижньорегістровий аліас (а LIKE у SQLite чутливий до
        // регістру для кирилиці).
        $variants->prepend($canonicalCity);

        return $variants
            ->unique(fn ($item) => self::normalizeCity((string) $item))
            ->values()
            ->all();
    }
}
