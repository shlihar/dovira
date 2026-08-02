<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProfileClaimRequest;
use App\Http\Requests\StoreOwnedProfileRequest;
use App\Models\Profile;
use App\Models\ProfileClaim;
use App\Models\Region;
use App\Services\ClaimVerificationService;
use App\Services\Notifications\TelegramAdminNotifier;
use App\Support\AuditLogger;
use App\Support\CategoryHierarchy;
use App\Support\RegionCityDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class ProfileClaimController extends Controller
{
    public function store(StoreProfileClaimRequest $request): RedirectResponse
    {
        $user = $request->user();
        $profile = Profile::query()->findOrFail((int) $request->validated('profile_id'));

        if ((int) $profile->owner_user_id === (int) $user->id) {
            return redirect()
                ->route('pro.account', ['profile' => $profile->id, 'tab' => 'overview'])
                ->with('status', 'claim-already-owned');
        }

        $claim = ProfileClaim::query()->firstOrNew([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
        ]);

        $claim->fill([
            'company_role' => $request->validated('company_role'),
            'proof_document_url' => $request->validated('proof_document_url'),
            'note' => $request->validated('note'),
            'status' => 'pending',
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
        ]);
        $claim->save();

        if ($claim->wasRecentlyCreated) {
            app(TelegramAdminNotifier::class)->newClaim($claim);
        }

        // Підтвердження прав безкоштовне; платне — керування профілем
        // (редагування, відповіді, контакти), воно відкривається з PRO.
        return redirect()
            ->route('pro.account', ['tab' => 'claims', 'claim_profile' => $profile->id])
            ->with('status', $claim->wasRecentlyCreated ? 'claim-submitted' : 'claim-updated');
    }

    /**
     * Крок 1 OTP: віддати доступні канали або надіслати код на вибраний
     * (email/телефон із профілю).
     */
    public function requestCode(Request $request, ClaimVerificationService $verifier): JsonResponse
    {
        $data = $request->validate([
            'profile_id' => ['required', 'integer'],
            'channel' => ['nullable', 'in:email,phone'],
            'destination_key' => ['nullable', 'string', 'max:128'],
        ]);

        $user = $request->user();
        $profile = Profile::query()->where('status', 'active')->findOrFail((int) $data['profile_id']);

        if ($profile->owner_user_id && (int) $profile->owner_user_id !== (int) $user->id) {
            return response()->json(['ok' => false, 'error' => 'Профіль уже має власника.'], 422);
        }

        $channels = $verifier->availableChannels($profile);

        // Без вибраного каналу — просто повертаємо, що доступно (email/телефон).
        if (empty($data['channel'])) {
            return response()->json(['ok' => true, 'channels' => $channels, 'manual' => empty($channels)]);
        }

        if (empty($channels)) {
            return response()->json(['ok' => false, 'error' => 'У профілі немає контактів для авто-підтвердження.'], 422);
        }

        $result = $verifier->sendCode($profile, $user, (string) $data['channel'], $data['destination_key'] ?? null);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * Крок 2 OTP: перевірити код і, якщо вірний, автоматично підтвердити права.
     */
    public function verifyCode(Request $request, ClaimVerificationService $verifier): JsonResponse
    {
        $data = $request->validate([
            'profile_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:12'],
        ]);

        $user = $request->user();
        $profile = Profile::query()->where('status', 'active')->findOrFail((int) $data['profile_id']);

        $result = $verifier->verify($profile, $user, (string) $data['code']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function createProfile(StoreOwnedProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $name = trim((string) $validated['name']);
        $regions = Region::query()->orderBy('name')->get(['id', 'name']);
        $resolvedCity = RegionCityDirectory::canonicalCity($this->nullableString($validated['city'] ?? null));
        $inferredRegionId = RegionCityDirectory::inferRegionId($resolvedCity, $regions);
        $categoryId = isset($validated['category_id']) ? (int) $validated['category_id'] : null;
        $subcategoryId = isset($validated['subcategory_id']) ? (int) $validated['subcategory_id'] : null;
        $subcategoryId = CategoryHierarchy::resolveValidSubcategoryId($categoryId, $subcategoryId);

        $profile = Profile::query()->create([
            'owner_user_id' => $user->id,
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
            'type' => 'company',
            'name' => $name,
            'slug' => $this->uniqueProfileSlug($name),
            'short_description' => $this->nullableString($validated['short_description'] ?? null),
            'website' => $this->nullableString($validated['website'] ?? null),
            'email' => $this->nullableString($validated['email'] ?? null),
            'phone' => $this->nullableString($validated['phone'] ?? null),
            'city' => $resolvedCity,
            'region_id' => $inferredRegionId ?? ($validated['region_id'] ?? null),
            'status' => 'draft',
            'is_published' => false,
            'show_in_catalog' => false,
            'is_owner_verified' => true,
        ]);

        CategoryHierarchy::syncProfileCategories($profile, $categoryId, $subcategoryId);

        AuditLogger::log('pro.profile_created_by_owner', $profile, [
            'created_by_owner' => $user->id,
        ]);

        app(TelegramAdminNotifier::class)->newProfile($profile);

        // Профіль створюється чернеткою безкоштовно; редагування і публікація
        // відкриються з PRO — вкладка «Профіль» покаже замок із CTA на оплату.
        return redirect()
            ->route('pro.account', ['tab' => 'profile', 'profile' => $profile->id])
            ->with('status', 'pro-profile-created');
    }

    private function uniqueProfileSlug(string $name): string
    {
        $base = Str::slug(Str::ascii($name)) ?: 'profile-' . Str::lower(Str::random(6));
        $slug = $base;
        $counter = 2;

        while (Profile::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
