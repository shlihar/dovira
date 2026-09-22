<?php

namespace App\Console\Commands;

use App\Mail\ProfileViewsDigest;
use App\Models\EmailLog;
use App\Models\Profile;
use App\Services\ProfileAnalyticsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Періодичний дайджест «Вас шукали»: власникам заявлених профілів БЕЗ PRO —
 * зведення переглядів/кліків/відгуків за останні 30 днів і заклик увімкнути PRO.
 * Шлеться раз на період (дедуп за темою з міткою місяця), лише коли за період
 * була активність. PRO-власники мають аналітику в кабінеті — їх пропускаємо.
 */
class SendProfileViewsDigest extends Command
{
    protected $signature = 'dovira:pro:views-digest
        {--dry-run : Показати, кому піде дайджест, без надсилання}
        {--limit=0 : Обмежити кількість оброблених профілів (0 — без ліміту)}';

    protected $description = 'Розсилає власникам не-PRO профілів місячний дайджест «Вас шукали»';

    public function handle(ProfileAnalyticsService $analytics): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $periodLabel = now()->translatedFormat('F Y');

        $processed = 0;
        $sent = 0;

        Profile::query()
            ->whereNotNull('owner_user_id')
            ->where('is_owner_verified', true)
            ->with('owner')
            ->orderBy('id')
            ->chunkById(200, function ($profiles) use ($analytics, $dryRun, $limit, $periodLabel, &$processed, &$sent): bool {
                foreach ($profiles as $profile) {
                    if ($limit > 0 && $processed >= $limit) {
                        return false;
                    }

                    // PRO уже має аналітику в кабінеті.
                    if ($profile->hasActiveProSubscription()) {
                        continue;
                    }

                    $ownerEmail = trim((string) ($profile->owner?->email ?? ''));
                    if ($ownerEmail === '' || ! filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
                        continue;
                    }

                    $prefs = (array) ($profile->notification_preferences ?? []);
                    if (($prefs['email_enabled'] ?? true) === false || ($prefs['analytics_digest_enabled'] ?? true) === false) {
                        continue;
                    }

                    $summary = $analytics->analyticsSummary($profile, 'last_30');
                    if ((int) $summary['views'] <= 0) {
                        continue; // немає про що звітувати
                    }

                    $processed++;

                    $stats = [
                        'views' => (int) $summary['views'],
                        'unique' => (int) $summary['unique'],
                        'contact_clicks' => (int) $summary['contact_clicks'],
                        'website_clicks' => (int) $summary['website_clicks'],
                        'reviews_count' => (int) $summary['reviews_count'],
                        'rating_avg' => (float) $summary['rating_avg'],
                    ];

                    $mailable = new ProfileViewsDigest(
                        $profile,
                        $periodLabel,
                        $stats,
                        route('pro.account.billing.pay', ['profile' => $profile->id]),
                    );
                    $subject = $mailable->envelope()->subject;

                    // Дедуп: цей місячний дайджест уже слали власнику.
                    $already = (bool) rescue(fn (): bool => EmailLog::query()
                        ->where('to_email', $ownerEmail)
                        ->where('subject', $subject)
                        ->exists(), false);
                    if ($already) {
                        continue;
                    }

                    if ($dryRun) {
                        $this->line(sprintf(
                            '[dry] #%d %s → %s (переглядів: %d, контакти: %d)',
                            $profile->id,
                            $profile->name,
                            $ownerEmail,
                            $stats['views'],
                            $stats['contact_clicks']
                        ));
                        $sent++;

                        continue;
                    }

                    $status = 'sent';
                    $error = null;

                    try {
                        Mail::to($ownerEmail)->send($mailable);
                    } catch (\Throwable $e) {
                        $status = 'failed';
                        $error = $e->getMessage();
                        Log::warning('Views digest failed to send.', [
                            'profile_id' => $profile->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    rescue(fn () => EmailLog::create([
                        'user_id' => $profile->owner_user_id,
                        'to_email' => $ownerEmail,
                        'subject' => $subject,
                        'status' => $status,
                        'error_message' => $error,
                        'sent_at' => $status === 'sent' ? now() : null,
                    ]));

                    if ($status === 'sent') {
                        $this->line(sprintf('#%d %s → %s', $profile->id, $profile->name, $ownerEmail));
                        $sent++;
                    }
                }

                return true;
            });

        $this->info(sprintf(
            '%s Оброблено: %d, %s: %d.',
            $dryRun ? '[dry-run]' : 'Готово.',
            $processed,
            $dryRun ? 'надіслали б' : 'надіслано',
            $sent
        ));

        return self::SUCCESS;
    }
}
