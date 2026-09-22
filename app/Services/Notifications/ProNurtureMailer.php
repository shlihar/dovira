<?php

namespace App\Services\Notifications;

use App\Mail\ProNurtureEmail;
use App\Models\EmailLog;
use App\Models\Profile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Розсилає кроки post-claim nurture-послідовності (config/nurture.php).
 * Крок `welcome` (day 0) шлеться одразу при підтвердженні профілю; решту —
 * щоденна команда dovira:pro:nurture-claimed. Кожен крок іде рівно раз на
 * власника профілю (дедуп за темою листа) і лише поки профіль без PRO.
 */
class ProNurtureMailer
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function steps(): array
    {
        return array_values((array) config('nurture.steps', []));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function stepConfig(string $key): ?array
    {
        foreach ($this->steps() as $step) {
            if (($step['key'] ?? null) === $key) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Надіслати крок за ключем. Повертає true, якщо лист реально пішов.
     */
    public function sendByKey(Profile $profile, string $key): bool
    {
        $step = $this->stepConfig($key);

        return $step ? $this->send($profile, $step) : false;
    }

    /**
     * Чи вже слали цей крок цьому власнику (для --dry-run, без надсилання).
     *
     * @param  array<string, mixed>  $stepDef
     */
    public function alreadySent(Profile $profile, array $stepDef): bool
    {
        $profile->loadMissing('owner');
        $email = trim((string) ($profile->owner?->email ?? ''));
        if ($email === '') {
            return false;
        }

        $subject = $this->resolveStep($profile, $stepDef)['subject'];

        return (bool) rescue(fn (): bool => EmailLog::query()
            ->where('to_email', $email)
            ->where('subject', $subject)
            ->exists(), false);
    }

    /**
     * @param  array<string, mixed>  $stepDef
     */
    public function send(Profile $profile, array $stepDef): bool
    {
        if (! $profile->owner_user_id) {
            return false;
        }

        $profile->loadMissing('owner');
        $ownerEmail = trim((string) ($profile->owner?->email ?? ''));
        if ($ownerEmail === '' || ! filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $prefs = (array) ($profile->notification_preferences ?? []);
        if (($prefs['email_enabled'] ?? true) === false) {
            return false;
        }

        $step = $this->resolveStep($profile, $stepDef);

        // Дедуп: цей крок уже надсилали цьому власнику для цього профілю.
        $already = (bool) rescue(fn (): bool => EmailLog::query()
            ->where('to_email', $ownerEmail)
            ->where('subject', $step['subject'])
            ->exists(), false);
        if ($already) {
            return false;
        }

        $mailable = new ProNurtureEmail($profile, $step);
        $status = 'sent';
        $error = null;

        try {
            Mail::to($ownerEmail)->send($mailable);
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
            Log::warning('Nurture email failed to send.', [
                'profile_id' => $profile->id,
                'step' => $stepDef['key'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        rescue(fn () => EmailLog::create([
            'user_id' => $profile->owner_user_id,
            'to_email' => $ownerEmail,
            'subject' => $step['subject'],
            'status' => $status,
            'error_message' => $error,
            'sent_at' => $status === 'sent' ? now() : null,
        ]));

        return $status === 'sent';
    }

    /**
     * Підставляє плейсхолдери й будує URL кнопки.
     *
     * @param  array<string, mixed>  $stepDef
     * @return array{subject:string, eyebrow:string, heading:string, body:array<int,string>, cta_label:string, cta_url:string}
     */
    private function resolveStep(Profile $profile, array $stepDef): array
    {
        $stepDef = $this->stepForProfile($profile, $stepDef);

        $replace = [
            '{name}' => (string) $profile->name,
            '{views}' => number_format((int) $profile->views_count, 0, '.', ' '),
        ];

        $sub = fn (string $value): string => strtr($value, $replace);

        return [
            'subject' => $sub((string) ($stepDef['subject'] ?? 'Dovira')),
            'eyebrow' => $sub((string) ($stepDef['eyebrow'] ?? '')),
            'heading' => $sub((string) ($stepDef['heading'] ?? '')),
            'body' => array_map($sub, (array) ($stepDef['body'] ?? [])),
            'cta_label' => (string) ($stepDef['cta_label'] ?? 'Відкрити кабінет'),
            'cta_url' => $this->ctaUrl($profile, (string) ($stepDef['cta'] ?? 'overview')),
        ];
    }

    /**
     * @param  array<string, mixed>  $stepDef
     * @return array<string, mixed>
     */
    private function stepForProfile(Profile $profile, array $stepDef): array
    {
        $verdict = (string) ($profile->dossier_verdict ?? '');
        $variant = (array) data_get($stepDef, 'variants.' . $verdict, []);

        if ($variant === []) {
            return $stepDef;
        }

        unset($stepDef['variants']);

        return array_replace($stepDef, $variant);
    }

    private function ctaUrl(Profile $profile, string $cta): string
    {
        return match ($cta) {
            'billing' => route('pro.account.billing.pay', ['profile' => $profile->id]),
            'reviews' => route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id]),
            default => route('pro.account', ['tab' => 'overview', 'profile' => $profile->id]),
        };
    }
}
