<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Str;

/** Conservative import identity checks. Shared contact details are not identity. */
final class ImportedProfileIdentity
{
    public static function key(?string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii((string) $value))));
    }

    public static function sameName(?string $left, ?string $right): bool
    {
        $key = self::key($left);

        return $key !== '' && $key === self::key($right);
    }

    public static function cityKey(?string $city): string
    {
        return self::key(RegionCityDirectory::canonicalCity((string) $city) ?? $city);
    }

    public static function sameLocation(Profile $profile, string $city, string $address): bool
    {
        return self::cityKey($city) !== ''
            && self::cityKey($city) === self::cityKey($profile->city)
            && self::key($address) !== ''
            && self::key($address) === self::key($profile->address);
    }

    public static function assertSameBusiness(Profile $profile, string $name, string $city = ''): void
    {
        if (str_contains((string) $profile->internal_note, '[IDENTITY_REPAIR_REVIEW]')
            || ! self::sameName($profile->name, $name)
            || (self::cityKey($city) !== '' && self::cityKey($profile->city) !== ''
                && self::cityKey($city) !== self::cityKey($profile->city))) {
            throw new \RuntimeException("Import identity conflict for profile #{$profile->id}; review its source mapping before importing.");
        }
    }
}
