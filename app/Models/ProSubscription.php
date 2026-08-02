<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProSubscription extends Model
{
    protected $fillable = [
        'profile_id',
        'plan',
        'billing_period',
        'status',
        'price_monthly',
        'price_yearly',
        'currency',
        'started_at',
        'ends_at',
        'trial_ends_at',
        'canceled_at',
    ];

    protected $casts = [
        'price_monthly' => 'integer',
        'price_yearly' => 'integer',
        'started_at' => 'datetime',
        'ends_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'canceled_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ProSubscriptionPayment::class);
    }
}
