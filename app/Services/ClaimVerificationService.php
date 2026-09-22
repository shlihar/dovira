<?php

namespace App\Services;

use App\Mail\ProfileClaimCodeMail;
use App\Models\Profile;
use App\Models\ProfileClaim;
use App\Models\ProfileClaimVerification;
use App\Models\User;
use App\Services\Sms\SmsService;
use Illuminate\Support\Facades\Mail;

/**
 * OTP-підтвердження прав на профіль: код надсилаємо на email/телефон,
 * ВКАЗАНИЙ У ПРОФІЛІ. Хто контролює цей контакт — той і власник, тож апрув
 * автоматичний, без ручної модерації. Ручна перевірка лишається fallback-ом
 * для профілів без контактів (стара форма заявки).
 */
class ClaimVerificationService
{
    private const CODE_TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    private const RESEND_COOLDOWN_SECONDS = 60;

    private const MAX_PER_HOUR = 6;

    /**
     * SMS-канал тимчасово вимкнено (рішення 2026-08-25): підтвердження лише
     * через email або підтримку. Інфраструктура SMS лишається — щоб повернути,
     * достатньо перемкнути прапорець.
     */
    private const SMS_CHANNEL_ENABLED = false;

    public function __construct(private SmsService $sms) {}

    /**
     * Канали, доступні для цього профілю (email завжди, телефон — лише коли
     * увімкнено SMS-канал і налаштований SMS-провайдер).
     *
     * @return array<int, array{channel: string, masked: string, destination_key: string}>
     */
    public function availableChannels(Profile $profile): array
    {
        $channels = [];

        $email = trim((string) $profile->email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $channels[] = [
                'channel' => 'email',
                // Email, як і телефон, показуємо ПОВНІСТЮ: це контакт бізнесу
                // з публічного профілю, власнику треба бачити, куди прийде код.
                'masked' => $email,
                'destination_key' => $this->destinationKey($email),
            ];
        }

        if (self::SMS_CHANNEL_ENABLED && $this->sms->enabled()) {
            foreach ($this->phoneDestinations($profile) as $phone) {
                $channels[] = [
                    'channel' => 'phone',
                    'masked' => $this->formatPhone($phone),
                    'destination_key' => $this->destinationKey($phone),
                ];
            }
        }

        return $channels;
    }

    /**
     * Згенерувати й надіслати код на вибраний канал.
     *
     * @return array{ok: bool, error?: string, masked?: string, ttl?: int, channel?: string}
     */
    public function sendCode(Profile $profile, User $user, string $channel, ?string $destinationKey = null): array
    {
        $availableChannels = collect($this->availableChannels($profile));
        $available = $destinationKey
            ? $availableChannels->first(fn (array $candidate): bool => $candidate['channel'] === $channel && $candidate['destination_key'] === $destinationKey)
            : $availableChannels->firstWhere('channel', $channel);
        if (! $available) {
            return ['ok' => false, 'error' => 'Цей канал підтвердження недоступний для профілю.'];
        }

        $destination = $this->destinationForChannel($profile, $channel, $available['destination_key']);

        // Антиспам: кулдаун між надсиланнями і погодинна квота на користувача.
        $lastForChannel = ProfileClaimVerification::query()
            ->where('profile_id', $profile->id)
            ->where('user_id', $user->id)
            ->where('channel', $channel)
            ->where('destination', $destination)
            ->latest('id')
            ->first();
        if ($lastForChannel && $lastForChannel->created_at->gt(now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))) {
            $wait = self::RESEND_COOLDOWN_SECONDS - now()->diffInSeconds($lastForChannel->created_at);
            return ['ok' => false, 'error' => 'Зачекайте ' . max(1, $wait) . ' с перед повторним надсиланням.'];
        }

        $hourly = ProfileClaimVerification::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHour())
            ->count();
        if ($hourly >= self::MAX_PER_HOUR) {
            return ['ok' => false, 'error' => 'Забагато спроб. Спробуйте за годину.'];
        }

        $code = (string) random_int(100000, 999999);

        ProfileClaimVerification::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'channel' => $channel,
            'destination' => $destination,
            'code_hash' => $this->hash($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
        ]);

        $sent = $channel === 'email'
            ? $this->sendEmail($destination, $code, (string) $profile->name)
            : $this->sms->send($destination, "Код підтвердження профілю Dovira: {$code}");

        if (! $sent) {
            return ['ok' => false, 'error' => 'Не вдалося надіслати код. Спробуйте інший канал або пізніше.'];
        }

        return [
            'ok' => true,
            'channel' => $channel,
            'masked' => $available['masked'],
            'ttl' => self::CODE_TTL_MINUTES,
        ];
    }

    /**
     * Перевірити введений код і, якщо вірний, автоматично підтвердити права.
     *
     * @return array{ok: bool, error?: string, redirect?: string}
     */
    public function verify(Profile $profile, User $user, string $code): array
    {
        // Профіль уже належить іншому користувачу — не переприв'язуємо через OTP.
        if ($profile->owner_user_id && (int) $profile->owner_user_id !== (int) $user->id) {
            return ['ok' => false, 'error' => 'Профіль уже має власника. Зверніться до підтримки.'];
        }

        $record = ProfileClaimVerification::query()
            ->where('profile_id', $profile->id)
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $record) {
            return ['ok' => false, 'error' => 'Код не знайдено або він протермінований. Надішліть новий.'];
        }

        if ($record->attempts >= self::MAX_ATTEMPTS) {
            return ['ok' => false, 'error' => 'Забагато невдалих спроб. Надішліть новий код.'];
        }

        $record->increment('attempts');

        if (! hash_equals($record->code_hash, $this->hash(trim($code)))) {
            return ['ok' => false, 'error' => 'Невірний код.'];
        }

        $record->update(['consumed_at' => now()]);

        $claim = ProfileClaim::query()->firstOrNew([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
        ]);
        $claim->status = 'pending';
        $claim->save();

        app(ProfileClaimReviewService::class)->approveViaVerification($claim, $record->channel);

        // Після безкоштовного підтвердження ведемо власника В КАБІНЕТ (огляд зі
        // статистикою переглядів + керування назвою), а не на оплату — спершу
        // людина бачить цінність, і лише потім вирішує щодо PRO.
        return [
            'ok' => true,
            'redirect' => route('pro.account', ['tab' => 'overview', 'profile' => $profile->id]),
        ];
    }

    private function sendEmail(string $email, string $code, string $profileName): bool
    {
        try {
            Mail::to($email)->send(new ProfileClaimCodeMail($code, $profileName, self::CODE_TTL_MINUTES));

            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Claim OTP email failed: ' . $e->getMessage());

            return false;
        }
    }

    private function hash(string $code): string
    {
        return hash('sha256', $code . '|' . config('app.key'));
    }

    /**
     * Телефон показуємо ПОВНІСТЮ: це контакт бізнесу з публічного профілю,
     * а власнику зручніше бачити, на який саме номер прийде код.
     */
    private function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 12 && str_starts_with($digits, '380')) {
            return '+380 ' . substr($digits, 3, 2) . ' ' . substr($digits, 5, 3) . ' ' . substr($digits, 8, 2) . ' ' . substr($digits, 10, 2);
        }

        return '+' . $digits;
    }

    /**
     * @return array<int, string>
     */
    private function phoneDestinations(Profile $profile): array
    {
        $raw = trim((string) $profile->phone);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:,|;|\/|\||\r\n|\r|\n|\bабо\b|\bor\b)\s*/iu', $raw) ?: [];
        if (count($parts) === 1) {
            $parts = [$raw];
        }

        return collect($parts)
            ->map(fn (string $phone): ?string => $this->sms->normalize($phone))
            ->filter()
            ->filter(fn (string $digits): bool => $this->canReceiveSms($digits))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * SMS доходить лише на мобільні номери: стаціонарні (380 + код міста,
     * напр. 044/041) відсіюємо, щоб не пропонувати канал, який не спрацює.
     */
    private function canReceiveSms(string $digits): bool
    {
        if (strlen($digits) !== 12 || ! str_starts_with($digits, '380')) {
            // Неукраїнський формат — не класифікуємо, лишаємо як є.
            return true;
        }

        return in_array(substr($digits, 3, 2), [
            '39', '50', '63', '66', '67', '68', '73', '75', '77', '89',
            '91', '92', '93', '94', '95', '96', '97', '98', '99',
        ], true);
    }

    private function destinationForChannel(Profile $profile, string $channel, string $destinationKey): string
    {
        if ($channel === 'email') {
            return trim((string) $profile->email);
        }

        return collect($this->phoneDestinations($profile))
            ->first(fn (string $phone): bool => $this->destinationKey($phone) === $destinationKey)
            ?? trim((string) $profile->phone);
    }

    private function destinationKey(string $destination): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($destination)), (string) config('app.key'));
    }
}
