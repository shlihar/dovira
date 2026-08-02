<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProSubscriptionPayment extends Model
{
    protected $fillable = [
        'pro_subscription_id',
        'profile_id',
        'user_id',
        'provider',
        'reference',
        'plan',
        'billing_period',
        'description',
        'amount',
        'currency',
        'status',
        'paid_at',
        'meta',
    ];

    protected $casts = [
        'amount' => 'integer',
        'paid_at' => 'datetime',
        'meta' => 'array',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ProSubscription::class, 'pro_subscription_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
