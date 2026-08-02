<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentOrder extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REVERSED = 'reversed';

    public const METHOD_MONOPAY = 'monopay';

    public const PRODUCT_GENERIC = 'generic';
    public const PRODUCT_PRO_SUBSCRIPTION = 'pro_subscription';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function markPaid(array $attributes = []): bool
    {
        if ($this->isPaid()) {
            return false;
        }

        $this->fill(array_merge([
            'status' => self::STATUS_PAID,
            'paid_at' => now(),
        ], $attributes))->save();

        return true;
    }
}
