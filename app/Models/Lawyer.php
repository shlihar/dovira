<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lawyer extends Model
{
    protected $fillable = [
        'source_hash',
        'full_name',
        'certificate_number',
        'certificate_issued_at',
        'certificate_issuer',
        'decision_number',
        'decision_at',
        'email',
        'photo_url',
        'is_suspended',
        'notes',
        'region_id',
    ];

    protected $casts = [
        'certificate_issued_at' => 'date',
        'decision_at' => 'date',
        'is_suspended' => 'boolean',
    ];

    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }
}
