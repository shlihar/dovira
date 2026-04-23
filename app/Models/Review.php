<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $fillable = [
        'lawyer_id',
        'author_name',
        'author_email',
        'rating',
        'body',
        'status',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function lawyer()
    {
        return $this->belongsTo(Lawyer::class);
    }
}
