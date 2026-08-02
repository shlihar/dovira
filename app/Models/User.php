<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'avatar_url',
        'google_id',
        'telegram_id',
        'telegram_username',
        'auth_provider',
        'email_notifications_enabled',
        'role',
        'status',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'email_notifications_enabled' => 'boolean',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $user): void {
            // owner_user_id занулиться на рівні БД (nullOnDelete), але прапорці
            // ні — інакше профіль лишиться «підтверджений власником» + PRO без
            // власника. Скидаємо їх, поки звʼязок ще є.
            $user->ownedProfiles()
                ->where(fn ($query) => $query->where('is_owner_verified', true)->orWhere('is_pro', true))
                ->update([
                    'is_owner_verified' => false,
                    'is_pro' => false,
                ]);
        });
    }

    /**
     * Telegram sign-ups without an email get a placeholder address
     * (см. SocialAuthService::placeholderEmail) — there is no inbox
     * behind it, so a verification email would only bounce.
     */
    public function sendEmailVerificationNotification(): void
    {
        if (str_ends_with(strtolower((string) $this->email), '@social.dovira.local')) {
            return;
        }

        $this->notify(new \Illuminate\Auth\Notifications\VerifyEmail());
    }

    public function ownedProfiles(): HasMany
    {
        return $this->hasMany(Profile::class, 'owner_user_id');
    }

    public function profileReviews(): HasMany
    {
        return $this->hasMany(ProfileReview::class, 'user_id');
    }

    public function profileClaims(): HasMany
    {
        return $this->hasMany(ProfileClaim::class, 'user_id');
    }

    public function platformNotifications(): HasMany
    {
        return $this->hasMany(PlatformNotification::class, 'user_id');
    }

    public function reviewReplies(): HasMany
    {
        return $this->hasMany(ReviewReply::class, 'author_user_id');
    }

    public function profileReviewReactions(): HasMany
    {
        return $this->hasMany(ProfileReviewReaction::class, 'user_id');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return in_array($this->role, ['admin', 'moderator', 'editor', 'support'], true) && $this->status === 'active';
    }

    public function hasAdminPermission(string $permission): bool
    {
        $rolePermissions = config('admin_permissions.roles.' . $this->role, []);

        return in_array('*', $rolePermissions, true) || in_array($permission, $rolePermissions, true);
    }
}
