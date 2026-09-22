<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запит PRO-власника на перегляд досьє: що неточне / що змінити.
 * Досьє переробляє команда — бізнес його не редагує.
 */
class DossierRevisionRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_DONE = 'done';

    public const STATUS_REJECTED = 'rejected';

    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_IN_REVIEW];

    protected $fillable = [
        'profile_id',
        'user_id',
        'message',
        'status',
        'admin_note',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
