<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Єдине джерело правди для тарифу PRO. Стартова пропозиція діє для першої
 * когорти передплатників: підписка з автопродовженням, списання раз на
 * 6 місяців. Після завершення когорти нові підписки коштуватимуть
 * FUTURE_AMOUNT — тоді достатньо змінити CURRENT_AMOUNT.
 *
 * ВАЖЛИВО: CURRENT_AMOUNT / FUTURE_AMOUNT — це суми СПИСАННЯ (грн), які йдуть
 * у monopay (він працює лише в гривні). Показова ціна (DISPLAY_*) також у
 * гривні й збігається з реальним списанням.
 *
 * Кількість підтверджених профілів і PRO-акаунтів НІДЕ не показуємо публічно —
 * жодних лічильників когорти. Тому launchCohortCounterLine / RemainingLine
 * лишаються порожніми (їх ще викликають старі шаблони й листи).
 */
final class ProPricing
{
    public const CURRENT_AMOUNT = 1999;

    public const FUTURE_AMOUNT = 3999;

    public const CURRENCY = 'UAH';

    /** Показова ціна на сайті — у гривні (збігається з реальним списанням). */
    public const DISPLAY_CURRENT = 1999;

    public const DISPLAY_FUTURE = 3999;

    public const DISPLAY_CURRENCY = 'UAH';

    public const PERIOD_KEY = 'halfyear';

    public const PERIOD_MONTHS = 6;

    public const PERIOD_LABEL = 'за 6 місяців';

    public const LAUNCH_COHORT_LIMIT = 500;

    /** Сума списання у гривні (для чеків, історії платежів, «буде списано»). */
    public static function formatAmount(int $amount): string
    {
        return number_format($amount, 0, '', ' ') . ' грн';
    }

    /** Показова ціна для вітрини (гривні). */
    public static function formatDisplay(int $amount): string
    {
        return number_format($amount, 0, '', ' ') . ' грн';
    }

    /**
     * Місячний еквівалент піврічної ціни — головна показова цифра вітрини
     * («≈ 333 грн/міс»). Списання лишається раз на 6 місяців повною сумою.
     */
    public static function monthlyEquivalent(int $periodAmount): int
    {
        return (int) round($periodAmount / max(1, self::PERIOD_MONTHS));
    }

    public static function monthlyEquivalentDisplay(int $periodAmount): string
    {
        return '≈ ' . number_format(self::monthlyEquivalent($periodAmount), 0, '', ' ') . ' грн/міс';
    }

    public static function launchCohortLabel(): string
    {
        return 'стартова пропозиція';
    }

    public static function launchCohortTitle(): string
    {
        return 'Стартова пропозиція';
    }

    public static function launchCohortPriceLine(): string
    {
        return 'Стартова ціна ' . self::formatDisplay(self::DISPLAY_CURRENT)
            . ' за 6 місяців. Далі — ' . self::formatDisplay(self::DISPLAY_FUTURE) . '.';
    }

    /** @deprecated Повідомлення про «фіксацію назавжди» прибрано. */
    public static function launchCohortLockLine(): string
    {
        return '';
    }

    /** @deprecated Публічний лічильник когорти прибрано з усіх сторінок. */
    public static function launchCohortCounterLine(): string
    {
        return '';
    }

    /** @deprecated Публічний лічильник когорти прибрано з усіх сторінок. */
    public static function launchCohortRemainingLine(): string
    {
        return '';
    }
}
