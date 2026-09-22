<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Services\Sms\SmsService;
use App\Support\CategoryHierarchy;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * SMS-розсилка по адвокатах із ЧЕРВОНИМ досьє (незаявлені, з телефоном) через
 * TurboSMS. Найгарячіший сегмент: у людини публічно висить проблемне досьє,
 * мотивація відповісти найвища. Текст короткий (SMS платні, кирилиця ~70
 * символів/сегмент), посилання — коротке /s/{id}.
 *
 * Дедуп без міграцій: прапорець notification_preferences.sms_dossier_sent_at.
 * Поважає outreach_opted_out. Обовʼязково спершу --dry-run (показує вартість).
 */
class SendOutreachSmsCampaign extends Command
{
    protected $signature = 'dovira:outreach:sms-campaign
        {--category=Адвокати : Назва категорії}
        {--verdict=red : Колір досьє (red|yellow|green)}
        {--limit=50 : Максимум SMS за один запуск}
        {--sleep=300 : Пауза між SMS, мс}
        {--dry-run : Показати, кому й що піде + оцінку сегментів, без надсилання}';

    protected $description = 'SMS-розсилка по червоних досьє-адвокатах через TurboSMS';

    /** Коротке SMS (без {імя}); {лінк} → /s/{id}. */
    private const TEXT = 'Доброго дня! На платформі Dovira опубліковано ваше досьє з відкритих джерел. Перегляньте, чи все коректно: {лінк}';

    public function handle(SmsService $sms): int
    {
        if (! $sms->enabled()) {
            $this->error('TurboSMS не налаштований (немає TURBOSMS_TOKEN).');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep'));
        $verdict = (string) $this->option('verdict');

        $ids = CategoryHierarchy::categoryIdsFromNames([(string) $this->option('category')]);
        if (empty($ids)) {
            $this->error('Категорію не знайдено.');

            return self::FAILURE;
        }

        $balance = $sms->balance();
        $this->info('Баланс TurboSMS: ' . ($balance ?? '—') . ' | сегмент кирилиці = 70 символів');

        $candidates = Profile::query()
            ->where('status', 'active')
            ->whereNull('owner_user_id')
            ->whereNotNull('dossier')
            ->where('dossier_verdict', $verdict)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->whereIn('id', fn ($q) => $q->select('profile_id')->from('profile_category')->whereIn('category_id', $ids))
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'phone', 'notification_preferences']);

        $this->info(($dryRun ? '[dry-run] ' : '') . "Кандидатів ({$verdict} + телефон): " . $candidates->count());

        $publicBase = rtrim((string) config('outreach.public_url', 'https://mydovira.com'), '/');

        $sent = 0;
        $skippedDup = 0;
        $skippedOpt = 0;
        $skippedPhone = 0;
        $segmentsTotal = 0;

        foreach ($candidates as $profile) {
            if ($sent >= $limit) {
                break;
            }

            $prefs = (array) ($profile->notification_preferences ?? []);
            if (! empty($prefs['outreach_opted_out'])) {
                $skippedOpt++;

                continue;
            }
            if (! empty($prefs['sms_dossier_sent_at'])) {
                $skippedDup++;

                continue;
            }

            $phone = $this->firstPhone($sms, (string) $profile->phone);
            if ($phone === null) {
                $skippedPhone++;

                continue;
            }

            $link = $publicBase . route('outreach.sms-link', ['profile' => $profile->id], false);
            $text = str_replace('{лінк}', $link, self::TEXT);
            $segments = (int) ceil(mb_strlen($text) / 70);
            $segmentsTotal += $segments;

            if ($dryRun) {
                $this->line(sprintf('  #%d %s → %s (%d сегм.) %s', $profile->id, mb_substr($profile->name, 0, 26), $phone, $segments, $link));
                $sent++;

                continue;
            }

            $ok = $sms->send($phone, $text);

            if ($ok) {
                $prefs['sms_dossier_sent_at'] = now()->toDateString();
                rescue(fn () => $profile->forceFill(['notification_preferences' => $prefs])->save());
                $this->line(sprintf('  ✓ #%d %s → %s', $profile->id, mb_substr($profile->name, 0, 26), $phone));
                $sent++;
            } else {
                $this->line(sprintf('  ✗ #%d %s → %s (не надіслано)', $profile->id, mb_substr($profile->name, 0, 26), $phone));
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s SMS: %d (≈%d сегментів), дублі: %d, opt-out: %d, без валідного тел.: %d.',
            $dryRun ? '[dry-run]' : 'Готово.',
            $sent,
            $segmentsTotal,
            $skippedDup,
            $skippedOpt,
            $skippedPhone
        ));

        return self::SUCCESS;
    }

    /** Перший валідний номер із поля (там бувають кілька через кому/«або»). */
    private function firstPhone(SmsService $sms, string $raw): ?string
    {
        $parts = preg_split('/\s*(?:,|;|\/|\||\bабо\b|\bor\b|\r\n|\r|\n)\s*/iu', $raw) ?: [$raw];
        foreach ($parts as $part) {
            $normalized = $sms->normalize((string) $part);
            // Валідний укр. мобільний: 380 + 9 цифр = 12.
            if ($normalized !== null && Str::startsWith($normalized, '380') && strlen($normalized) === 12) {
                return $normalized;
            }
        }

        return null;
    }
}
