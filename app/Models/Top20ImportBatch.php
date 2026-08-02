<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Top20ImportBatch extends Model
{
    protected $fillable = [
        'user_id',
        'category_id',
        'subcategory_id',
        'name',
        'status',
        'city_code',
        'city_name',
        'region_name',
        'listing_url',
        'options',
        'pages_visited',
        'cards_found',
        'profiles_considered',
        'profiles_created',
        'profiles_updated',
        'profiles_skipped',
        'reviews_created',
        'reviews_updated',
        'reviews_hidden',
        'errors_count',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'options' => 'array',
        'pages_visited' => 'integer',
        'cards_found' => 'integer',
        'profiles_considered' => 'integer',
        'profiles_created' => 'integer',
        'profiles_updated' => 'integer',
        'profiles_skipped' => 'integer',
        'reviews_created' => 'integer',
        'reviews_updated' => 'integer',
        'reviews_hidden' => 'integer',
        'errors_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $batch): void {
            $batch->deleteImportedDraftProfiles();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'subcategory_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Top20ImportItem::class, 'batch_id');
    }

    public function deleteImportedDraftProfiles(): void
    {
        $profileIds = $this->items()
            ->where('profile_was_created', true)
            ->whereNotNull('profile_id')
            ->pluck('profile_id')
            ->map(static fn (mixed $profileId): int => (int) $profileId)
            ->unique()
            ->values();

        if ($profileIds->isEmpty()) {
            return;
        }

        Profile::query()
            ->whereIn('id', $profileIds)
            ->get()
            ->each(function (Profile $profile): void {
                if (! $this->shouldDeleteImportedDraftProfile($profile)) {
                    return;
                }

                $profile->delete();
            });
    }

    private function shouldDeleteImportedDraftProfile(Profile $profile): bool
    {
        if ($profile->status !== 'draft') {
            return false;
        }

        if ((bool) $profile->is_published || (bool) $profile->show_in_catalog) {
            return false;
        }

        return ! $profile->top20ImportItems()
            ->where('batch_id', '!=', $this->id)
            ->exists();
    }
}
