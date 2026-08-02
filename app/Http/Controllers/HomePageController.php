<?php

namespace App\Http\Controllers;

use App\Services\HomePageDataService;
use App\Support\BlogPosts;
use Illuminate\Http\Request;

class HomePageController extends Controller
{
    /**
     * A/B-пул hero головної: 'base' — поточна версія (контроль),
     * решта — варіанти C/F/I/J. Призначення липке (кука), щоб людина
     * завжди бачила свій варіант; ?hero=x — ручний перегляд для QA.
     */
    public const HERO_VARIANTS = ['base', 'c', 'f', 'i', 'j'];

    /**
     * Живий A/B: коли false — усі бачать base (контроль), а варіанти
     * доступні лише вручну через ?hero=x (прев'ю). Коли true — новим
     * відвідувачам випадково призначається варіант (липка кука).
     * Тримаємо false, поки не затвердимо варіанти й не вирішимо стартувати.
     */
    private const HERO_AB_ENABLED = false;

    private const HERO_COOKIE = 'hero_variant';
    private const HERO_COOKIE_MINUTES = 60 * 24 * 90;

    public function __invoke(Request $request, HomePageDataService $homePageData): \Illuminate\Contracts\View\View
    {
        [$heroVariant, $setCookie] = $this->resolveHeroVariant($request);

        $view = view('static.home', $homePageData->getPayload() + [
            'blogPosts' => array_slice(BlogPosts::all(), 0, 3),
            'heroVariant' => $heroVariant,
        ]);

        if ($setCookie) {
            cookie()->queue(self::HERO_COOKIE, $heroVariant, self::HERO_COOKIE_MINUTES);
        }

        return $view;
    }

    /**
     * @return array{0: string, 1: bool} [варіант, чи треба (пере)ставити куку]
     */
    private function resolveHeroVariant(Request $request): array
    {
        // Ручний override для перегляду/QA — не фіксуємо в куку, щоб
        // прев'ю одного варіанта не «залипало» на наступні заходи.
        $forced = strtolower(trim((string) $request->query('hero', '')));
        if (in_array($forced, self::HERO_VARIANTS, true)) {
            return [$forced, false];
        }

        // A/B вимкнено — усі бачать контроль (base). Ротація тільки коли
        // явно ввімкнемо прапорцем.
        if (! self::HERO_AB_ENABLED) {
            return ['base', false];
        }

        $fromCookie = strtolower(trim((string) $request->cookie(self::HERO_COOKIE, '')));
        if (in_array($fromCookie, self::HERO_VARIANTS, true)) {
            return [$fromCookie, false];
        }

        // Пошукові боти без кук: завжди контроль — стабільний контент для SEO.
        if (preg_match('/bot|crawl|spider|slurp|bingpreview/i', (string) $request->userAgent())) {
            return ['base', false];
        }

        return [self::HERO_VARIANTS[array_rand(self::HERO_VARIANTS)], true];
    }
}
