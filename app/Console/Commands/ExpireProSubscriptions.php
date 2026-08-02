<?php

namespace App\Console\Commands;

use App\Models\ProSubscription;
use App\Services\Notifications\TelegramAdminNotifier;
use App\Services\ProProfileNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireProSubscriptions extends Command
{
    protected $signature = 'dovira:pro:expire-subscriptions {--dry-run : Показати, що буде деактивовано, без змін у БД}';

    protected $description = 'Деактивує прострочені PRO-підписки: status=expired і знімає is_pro з профілів без іншої активної підписки';

    public function handle(ProProfileNotificationService $notifications, TelegramAdminNotifier $telegram): int
    {
        $now = now();

        $expired = ProSubscription::query()
            ->with('profile')
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', $now)
            ->get();

        if ($expired->isEmpty()) {
            $this->info('Прострочених PRO-підписок немає.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $demotedProfiles = 0;

        foreach ($expired as $subscription) {
            $profile = $subscription->profile;
            $label = $profile ? "{$profile->name} (#{$profile->id})" : "profile #{$subscription->profile_id}";
            $this->line("Підписка #{$subscription->id} — {$label}, закінчилась {$subscription->ends_at->toDateTimeString()}");

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($subscription, $now, &$demotedProfiles): void {
                $subscription->forceFill(['status' => 'expired'])->save();

                $profile = $subscription->profile()->lockForUpdate()->first();

                if (! $profile) {
                    return;
                }

                $stillActive = $profile->proSubscriptions()
                    ->where('status', 'active')
                    ->where(function ($query) use ($now): void {
                        $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
                    })
                    ->exists();

                if (! $stillActive && $profile->is_pro) {
                    $profile->forceFill(['is_pro' => false])->save();
                    $demotedProfiles++;
                }
            });

            if ($profile) {
                $notifications->create(
                    $profile,
                    'pro_billing_expired',
                    'PRO-підписка завершилась',
                    'Термін підписки минув. Продовжте PRO на вкладці «Оплата», щоб повернути відповіді на відгуки та PRO-інструменти.',
                    ['severity' => 'warning']
                );
            }
        }

        if ($dryRun) {
            $this->warn("Dry-run: знайдено {$expired->count()} прострочених підписок, змін не внесено.");

            return self::SUCCESS;
        }

        $this->info("Деактивовано підписок: {$expired->count()}, знято is_pro з профілів: {$demotedProfiles}.");

        if ($expired->isNotEmpty()) {
            $telegram->send(sprintf(
                "⏳ PRO-експірація: деактивовано %d підписок, знято PRO з %d профілів.",
                $expired->count(),
                $demotedProfiles
            ));
        }

        return self::SUCCESS;
    }
}
