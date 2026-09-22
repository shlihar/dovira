<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Profile;

final class ProDiscount
{
    public static function percent(Profile $profile): int
    {
        return max(0, min(90, (int) ($profile->pro_discount_percent ?? 0)));
    }

    /**
     * @return array{base_amount:int,discount_percent:int,discount_amount:int,amount:int,has_discount:bool}
     */
    public static function price(Profile $profile, int $baseAmount): array
    {
        $baseAmount = max(0, $baseAmount);
        $percent = self::percent($profile);
        $discountAmount = $percent > 0
            ? min($baseAmount, (int) round($baseAmount * $percent / 100))
            : 0;
        $amount = max(0, $baseAmount - $discountAmount);

        return [
            'base_amount' => $baseAmount,
            'discount_percent' => $percent,
            'discount_amount' => $discountAmount,
            'amount' => $amount,
            'has_discount' => $percent > 0 && $discountAmount > 0,
        ];
    }
}
