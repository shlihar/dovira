<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Top20ImportItem extends Model
{
    protected $fillable = [
        'batch_id',
        'profile_id',
        'category_id',
        'subcategory_id',
        'status',
        'raw_name',
        'raw_city',
        'top20_url',
        'raw_payload',
        'reviews_created',
        'reviews_updated',
        'reviews_hidden',
        'profile_was_created',
        'profile_was_updated',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'reviews_created' => 'integer',
        'reviews_updated' => 'integer',
        'reviews_hidden' => 'integer',
        'profile_was_created' => 'boolean',
        'profile_was_updated' => 'boolean',
        'processed_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Top20ImportBatch::class, 'batch_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'subcategory_id');
    }
}
