<?php

namespace App\Console\Commands;

use App\Models\ProfileClaim;
use App\Services\Notifications\ProNurtureMailer;
use Illuminate\Console\Command;

/**
 * Щоденно рухає підтверджені, але ще НЕ-PRO профілі по nurture-послідовності
 * (config/nurture.php). За один запуск на профіль іде максимум один лист —
 * найраніший «прострочений» крок, який ще не надсилали. Дата відліку — коли
 * профіль підтверджено (reviewed_at схваленої заявки).
 */
class NurtureClaimedProfiles extends Command
{
    protected $signature = 'dovira:pro:nurture-claimed
        {--dry-run : Показати, кому й що піде, без надсилання}
        {--limit=0 : Обмежити кількість оброблених профілів (0 — без ліміту)}';

    protected $description = 'Розсилає post-claim nurture-листи адвокатам, що підтвердили профіль, але не оформили PRO';

    public function handle(ProNurtureMailer $mailer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        // Кроки з day > 0 за зростанням дня (day 0 шлеться при підтвердженні).
        $steps = collect($mailer->steps())
            ->filter(fn (array $s): bool => (int) ($s['day'] ?? 0) > 0)
            ->sortBy(fn (array $s): int => (int) $s['day'])
            ->values();

        if ($steps->isEmpty()) {
            $this->warn('У config/nurture.php немає кроків із day > 0.');

            return self::SUCCESS;
        }

        $processed = 0;
        $sent = 0;
        $now = now();

        ProfileClaim::query()
            ->where('status', 'approved')
            ->whereNotNull('reviewed_at')
            ->with(['profile.owner'])
            ->orderBy('id')
            ->chunkById(200, function ($claims) use ($mailer, $steps, $dryRun, $limit, $now, &$processed, &$sent): bool {
                foreach ($claims as $claim) {
                    if ($limit > 0 && $processed >= $limit) {
                        return false; // досягли ліміту — зупиняємо чанкінг
                    }

                    $profile = $claim->profile;
                    if (! $profile || ! $profile->owner_user_id) {
                        continue;
                    }

                    // Оформив PRO — випав із потоку.
                    if ($profile->hasActiveProSubscription()) {
                        continue;
                    }

                    $processed++;
                    $daysSince = (int) $claim->reviewed_at->startOfDay()->diffInDays($now->startOfDay());

                    foreach ($steps as $step) {
                        if ((int) $step['day'] > $daysSince) {
                            break; // далі лише «майбутні» кроки — виходимо
                        }

                        if ($dryRun) {
                            if (! $mailer->alreadySent($profile, $step)) {
                                $this->line(sprintf(
                                    '[dry] #%d %s — день %d → крок «%s» (day %d)',
                                    $profile->id,
                                    $profile->name,
                                    $daysSince,
                                    $step['key'],
                                    $step['day']
                                ));
                                $sent++;
                                break;
                            }

                            continue;
                        }

                        if ($mailer->send($profile, $step)) {
                            $this->line(sprintf('#%d %s → «%s»', $profile->id, $profile->name, $step['key']));
                            $sent++;
                            break;
                        }
                    }
                }

                return true;
            });

        $this->info(sprintf(
            '%s Оброблено профілів: %d, %s: %d.',
            $dryRun ? '[dry-run]' : 'Готово.',
            $processed,
            $dryRun ? 'надіслали б' : 'надіслано листів',
            $sent
        ));

        return self::SUCCESS;
    }
}
