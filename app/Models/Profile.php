<?php

namespace App\Models;

use App\Support\RegionCityDirectory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

class Profile extends Model
{
    protected $fillable = [
        'owner_user_id',
        'region_id',
        'type',
        'name',
        'short_description',
        'slug',
        'description',
        'description_original',
        'description_rewritten_at',
        'dossier',
        'dossier_source',
        'dossier_generated_at',
        'dossier_verdict',
        'dossier_verdict_note',
        'ai_review_summary',
        'ai_review_summary_generated_at',
        'ai_review_summary_source_count',
        'website',
        'contact_cta_url',
        'contact_cta_mode',
        'email',
        'phone',
        'address',
        'city',
        'district',
        'logo_url',
        'banner_url',
        'gallery',
        'social_links',
        'notification_preferences',
        'is_verified',
        'dovira_recommends',
        'dovira_recommendation_status',
        'is_owner_verified',
        'is_pro',
        'is_featured',
        'status',
        'is_published',
        'show_in_catalog',
        'sort_priority',
        'rating_avg',
        'google_rating',
        'google_reviews_count',
        'google_rating_fetched_at',
        'reviews_count',
        'views_count',
        'unique_views_count',
        'website_clicks_count',
        'contact_clicks_count',
        'popularity_score',
        'ai_confidence_score',
        'ai_enrichment_status',
        'ai_enriched_at',
        'ai_suggested_data',
        'recommend_percent',
        'seo_title',
        'seo_description',
        'og_image_url',
        'internal_note',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'ai_review_summary' => 'array',
        'ai_review_summary_generated_at' => 'datetime',
        'description_rewritten_at' => 'datetime',
        'dossier_generated_at' => 'datetime',
        'is_verified' => 'boolean',
        'dovira_recommends' => 'boolean',
        'is_owner_verified' => 'boolean',
        'is_pro' => 'boolean',
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
        'show_in_catalog' => 'boolean',
        'sort_priority' => 'integer',
        'gallery' => 'array',
        'social_links' => 'array',
        'notification_preferences' => 'array',
        'rating_avg' => 'decimal:2',
        'google_rating' => 'decimal:1',
        'google_reviews_count' => 'integer',
        'google_rating_fetched_at' => 'datetime',
        'reviews_count' => 'integer',
        'views_count' => 'integer',
        'unique_views_count' => 'integer',
        'website_clicks_count' => 'integer',
        'contact_clicks_count' => 'integer',
        'popularity_score' => 'decimal:2',
        'ai_confidence_score' => 'integer',
        'ai_enriched_at' => 'datetime',
        'ai_suggested_data' => 'array',
        'recommend_percent' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $profile): void {
            // Профіль без slug — битий URL сторінки; генеруємо з назви.
            if (blank($profile->slug) && filled($profile->name)) {
                $base = \Illuminate\Support\Str::slug($profile->name) ?: 'profile';
                $slug = $base;
                $i = 2;
                while (self::query()->where('slug', $slug)->when($profile->id, fn ($q) => $q->whereKeyNot($profile->id))->exists()) {
                    $slug = $base.'-'.$i;
                    $i++;
                }
                $profile->slug = $slug;
            }
        });
    }

    public function setCityAttribute(mixed $value): void
    {
        $rawCity = trim((string) $value);

        if ($rawCity === '') {
            $this->attributes['city'] = null;

            return;
        }

        $this->attributes['city'] = RegionCityDirectory::canonicalCity($rawCity) ?? $rawCity;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'profile_category')
            ->withPivot('is_primary')
            ->withTimestamps()
            ->orderByDesc('profile_category.is_primary')
            ->orderBy('categories.name');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(CategoryService::class, 'profile_category_service')
            ->withTimestamps();
    }

    public function primaryCategory(): BelongsToMany
    {
        return $this->categories()->wherePivot('is_primary', true);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProfileReview::class);
    }

    public function publishedReviews(): HasMany
    {
        return $this->reviews()->where('status', 'published');
    }

    public function officialReplies(): HasMany
    {
        return $this->hasMany(OfficialReply::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(ProfileClaim::class);
    }

    public function reviewReports(): HasManyThrough
    {
        return $this->hasManyThrough(
            ReviewReport::class,
            ProfileReview::class,
            'profile_id',
            'profile_review_id',
            'id',
            'id'
        );
    }

    public function proSubscriptions(): HasMany
    {
        return $this->hasMany(ProSubscription::class);
    }

    public function proSubscriptionPayments(): HasMany
    {
        return $this->hasMany(ProSubscriptionPayment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProfileEvent::class);
    }

    public function aiEnrichmentTasks(): HasMany
    {
        return $this->hasMany(AiEnrichmentTask::class);
    }

    public function dataSources(): HasMany
    {
        return $this->hasMany(ProfileDataSource::class);
    }

    public function top20ImportItems(): HasMany
    {
        return $this->hasMany(Top20ImportItem::class);
    }

    public function externalMentions(): HasMany
    {
        return $this->hasMany(ExternalProfileMention::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'subject_id')
            ->where('subject_type', self::class);
    }

    /**
     * Готовність профілю — за тими самими 6 пунктами, що показує чек-лист
     * у кабінеті (лише те, що власник заповнює редагуванням, без дублів).
     * Так відсоток у кільці й галочки в чек-листі завжди збігаються.
     */
    public function completenessPercent(): int
    {
        $checks = [
            // Основна інформація
            ! blank($this->name) && ! blank($this->description),
            // Контакти
            ! blank($this->phone) || ! blank($this->email) || ! blank($this->website),
            // Локація
            ! blank($this->city),
            // Послуги
            $this->services()->exists(),
            // Логотип
            ! blank($this->logo_url),
            // Фото та галерея
            ! empty($this->gallery),
        ];

        $total = count($checks);
        $done = count(array_filter($checks));

        return (int) round(($done / $total) * 100);
    }

    public function hasActiveProSubscription(?Carbon $at = null): bool
    {
        $at ??= now();

        $hasValidSubscription = $this->proSubscriptions()
            ->where('status', 'active')
            ->where(function ($query) use ($at): void {
                $query->whereNull('started_at')
                    ->orWhere('started_at', '<=', $at);
            })
            ->where(function ($query) use ($at): void {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $at);
            })
            ->exists();

        if ($hasValidSubscription) {
            return true;
        }

        // is_pro без жодної підписки — PRO, виданий адміном вручну (Filament).
        // Якщо підписки є, правда — саме вони: прострочена підписка не дає PRO,
        // навіть поки денормалізований is_pro ще не знятий командою експірації.
        return $this->is_pro && ! $this->proSubscriptions()->exists();
    }
}
