<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Єдине джерело правди для тарифу PRO. Стартова пропозиція на запуску:
 * розовий платіж за 6 місяців (без автосписання), ціна фіксується за
 * передплатником назавжди при продовженні. Після завершення акції нові
 * підписки коштуватимуть FUTURE_AMOUNT — тоді достатньо змінити CURRENT_AMOUNT.
 *
 * ВАЖЛИВО: CURRENT_AMOUNT / FUTURE_AMOUNT — це суми СПИСАННЯ (грн), які йдуть
 * у monopay (він працює лише в гривні). На сайті ж ціну ПОКАЗУЄМО в доларах
 * (DISPLAY_*), бо так вирішено маркетингово. Долар — суто вітрина, реально
 * списується гривня. Долари й гривні тримаємо синхронно вручну.
 */
final class ProPricing
{
    public const CURRENT_AMOUNT = 1999;

    public const FUTURE_AMOUNT = 7999;

    public const CURRENCY = 'UAH';

    /** Показова ціна на сайті (долари). Реальне списання — CURRENT_AMOUNT грн. */
    public const DISPLAY_CURRENT = 49;

    public const DISPLAY_FUTURE = 199;

    public const DISPLAY_CURRENCY = 'USD';

    public const PERIOD_KEY = 'halfyear';

    public const PERIOD_MONTHS = 6;

    public const PERIOD_LABEL = 'за 6 місяців';

    /** Сума списання у гривні (для чеків, історії платежів, «буде списано»). */
    public static function formatAmount(int $amount): string
    {
        return number_format($amount, 0, '', ' ') . ' грн';
    }

    /** Показова ціна для вітрини (долари). Не використовувати для чеків/списання. */
    public static function formatDisplay(int $amount): string
    {
        return '$' . number_format($amount, 0, '', ' ');
    }
}
