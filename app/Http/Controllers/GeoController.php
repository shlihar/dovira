<?php

namespace App\Http\Controllers;

use App\Services\HomePageDataService;
use App\Support\RegionCityDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeoController extends Controller
{
    private const CITY_COOKIE = 'dovira_city';
    private const COOKIE_DAYS = 30;

    public function __construct(
        private readonly HomePageDataService $homePageDataService,
    ) {
    }

    /**
     * Персоналізація головної за містом користувача: повертає HTML трьох
     * секцій («Dovira рекомендує», «Найпопулярніші адвокати»,
     * «Найпопулярніші компанії»), де профілі міста йдуть першими.
     *
     * Місто визначається за пріоритетом: ?city= (для тестів/дебагу) →
     * кукі → геолокація за IP. Браузерні дозволи не запитуються.
     */
    public function homeSections(Request $request): JsonResponse
    {
        $requestedCity = trim((string) $request->query('city', ''));

        // Явний вибір «Вся Україна» в селекторі: скидаємо збережене місто
        // і повертаємо глобальні секції (фронт відновлює SSR-розмітку).
        if (mb_strtolower($requestedCity) === 'all') {
            return response()->json([
                'status' => 'ok',
                'city' => null,
                'html' => null,
            ])->withoutCookie(self::CITY_COOKIE);
        }

        $explicitChoice = RegionCityDirectory::canonicalCity($requestedCity) !== null;

        $city = RegionCityDirectory::canonicalCity($requestedCity)
            ?? RegionCityDirectory::canonicalCity((string) $request->cookie(self::CITY_COOKIE, ''))
            ?? $this->cityFromIp((string) $request->ip());

        if ($city === null || $city === '') {
            // Місто не визначилось — секції лишаються глобальними (SSR).
            return response()->json([
                'status' => 'ok',
                'city' => null,
                'html' => null,
            ]);
        }

        $result = $this->homePageDataService->citySections($city);

        if (
            empty($result['sections']['recommended'])
            && empty($result['sections']['best'])
            && empty($result['sections']['trending'])
            && empty($result['sections']['leaderboard'])
            && empty($result['sections']['reviews'])
        ) {
            return response()->json([
                'status' => 'ok',
                'city' => null,
                'html' => null,
            ]);
        }

        $response = response()->json([
            'status' => 'ok',
            'city' => $result['city'],
            'html' => [
                'recommended' => view('static.partials.home-city-cards', [
                    'profiles' => $result['sections']['recommended'],
                ])->render(),
                // Лідерборд «Топ-рейтинги»: колонки цілком, бо його розмітка
                // відрізняється від каруселі карток.
                'top' => view('static.partials.home-top-board', [
                    'bestProfiles' => $result['sections']['best'] ?? [],
                    'trendingProfiles' => $result['sections']['trending'] ?? [],
                ])->render(),
                'reviews' => view('static.partials.home-review-cards', [
                    'reviews' => $result['sections']['reviews'] ?? [],
                ])->render(),
                'leaderboard' => view('static.partials.home-leaderboard', [
                    'leaderboard' => $result['sections']['leaderboard'] ?? [],
                ])->render(),
            ],
        ]);

        // Запам'ятовуємо перше успішне визначення (щоб наступні візити не
        // чекали на IP-сервіс) і кожен явний вибір міста в селекторі.
        if ($explicitChoice || ! $request->hasCookie(self::CITY_COOKIE)) {
            $response->cookie(self::CITY_COOKIE, $result['city'], self::COOKIE_DAYS * 24 * 60);
        }

        return $response;
    }

    private function cityFromIp(string $ip): ?string
    {
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        return Cache::remember('geo:ip-city:' . $ip, now()->addDay(), function () use ($ip): ?string {
            try {
                $response = Http::timeout(3)
                    ->connectTimeout(2)
                    ->get("https://get.geojs.io/v1/ip/geo/{$ip}.json");

                if (! $response->ok()) {
                    return null;
                }

                if (strtoupper((string) $response->json('country_code')) !== 'UA') {
                    return null;
                }

                return RegionCityDirectory::canonicalCity((string) $response->json('city'));
            } catch (\Throwable $exception) {
                Log::info('IP geolocation lookup failed.', [
                    'message' => $exception->getMessage(),
                ]);

                return null;
            }
        });
    }
}
