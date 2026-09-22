<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;

class SocialAuthService
{
    /**
     * @return array{user: User, created: bool}
     */
    public function resolveOauthUser(string $provider, SocialiteUserContract $socialUser): array
    {
        $email = $this->normalizeEmail($socialUser->getEmail());
        $name = trim((string) ($socialUser->getName() ?: $socialUser->getNickname() ?: ''));

        if ($name === '') {
            $name = $email !== null
                ? Str::before($email, '@')
                : 'Користувач ' . Str::title($provider);
        }

        return $this->resolveUser(
            provider: $provider,
            providerId: (string) $socialUser->getId(),
            email: $email,
            name: $name,
            avatarUrl: $this->nullableString($socialUser->getAvatar()),
        );
    }

    /**
     * @return array{user: User, created: bool}
     */
    private function resolveUser(
        string $provider,
        string $providerId,
        ?string $email,
        string $name,
        ?string $avatarUrl,
    ): array {
        $providerColumn = $this->providerColumn($provider);

        return DB::transaction(function () use ($provider, $providerId, $email, $name, $avatarUrl, $providerColumn): array {
            $created = false;

            $user = User::query()
                ->where($providerColumn, $providerId)
                ->first();

            if (! $user && $email !== null) {
                $user = User::query()
                    ->where('email', $email)
                    ->first();
            }

            if (! $user) {
                $created = true;
                $user = new User();
                $user->email = $email ?? $this->placeholderEmail($provider, $providerId);
                $user->password = Hash::make(Str::random(40));
            }

            if ($email !== null && ($created || $this->isPlaceholderEmail($user->email))) {
                $user->email = $email;
            }

            if (blank($user->name)) {
                $user->name = $name;
            }

            $user->{$providerColumn} = $providerId;
            $user->auth_provider ??= $provider;

            if (blank($user->avatar_url) && $avatarUrl !== null) {
                $user->avatar_url = $avatarUrl;
            }

            if ($email !== null && blank($user->email_verified_at)) {
                $user->email_verified_at = now();
            }

            $user->save();

            return [
                'user' => $user,
                'created' => $created,
            ];
        });
    }

    private function providerColumn(string $provider): string
    {
        return match ($provider) {
            'google' => 'google_id',
            default => throw new \InvalidArgumentException('Unsupported social provider: ' . $provider),
        };
    }

    private function normalizeEmail(?string $email): ?string
    {
        $value = trim((string) $email);

        return $value === '' ? null : Str::lower($value);
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function placeholderEmail(string $provider, string $providerId): string
    {
        return sprintf('%s-%s@social.dovira.local', $provider, $providerId);
    }

    private function isPlaceholderEmail(?string $email): bool
    {
        $value = Str::lower(trim((string) $email));

        return $value !== '' && str_ends_with($value, '@social.dovira.local');
    }
}
