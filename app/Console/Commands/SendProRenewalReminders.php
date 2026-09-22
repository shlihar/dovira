<?php

namespace App\Console\Commands;

use App\Mail\ProRenewalReminder;
use App\Models\EmailLog;
use App\Models\ProSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Нагадування власникам про завершення PRO: три хвилі — за ~7, 3 і 1 день
 * до ends_at. Дедуп по темі листа в email_logs (тема містить дату кінця
 * періоду, тож наступний період отримає нагадування знову).
 *
 * Це сервісні (транзакційні) листи про стан підписки — маркетингові
 * налаштування сповіщень їх не вимикають.
 *
 * Режим міграції: payments.renewal_reminders.manual_mode (див. config).
 */
class SendProRenewalReminders extends Command
{
    protected $signature = 'dovira:pro:renewal-reminders {--dry-run : Показати, кому надіслали б, без реальних листів}';

    protected $description = 'Нагадування про завершення PRO-підписки за 7/3/1 день до кінця';

    public function handle(): int
    {
        $manualMode = (bool) config('payments.renewal_reminders.manual_mode', true);

        $subscriptions = ProSubscription::query()
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [now(), now()->addDays(7)->endOfDay()])
            // Штатний режим: нагадуємо лише тим, у кого автосписання не
            // спрацює. У режимі міграції старі картки мертві — нагадуємо всім.
            ->when(! $manualMode, function ($query): void {
                $query->where(function ($q): void {
                    $q->where('auto_renew', false)->orWhereNull('monopay_card_token');
                });
            })
            ->with(['profile.owner'])
            ->get();

        if ($subscriptions->isEmpty()) {
            $this->info('Немає підписок, що завершуються протягом 7 днів.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($subscriptions as $subscription) {
            $profile = $subscription->profile;
            $ownerEmail = trim((string) ($profile?->owner?->email ?? ''));

            if ($profile === null || $ownerEmail === '' || ! filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
                $this->warn("Пропуск #{$subscription->id}: немає email власника.");

                continue;
            }

            $daysLeft = (int) now()->startOfDay()->diffInDays($subscription->ends_at->copy()->startOfDay(), false);
            $stage = $daysLeft >= 4 ? 'week' : ($daysLeft >= 2 ? 'days3' : 'day1');
            $subject = ProRenewalReminder::subjectFor($profile, $subscription, $stage);
            $label = sprintf('#%d %s → %s [%s, днів: %d]', $subscription->id, $profile->name, $ownerEmail, $stage, $daysLeft);

            $already = (bool) rescue(fn (): bool => EmailLog::query()
                ->where('to_email', $ownerEmail)
                ->where('subject', $subject)
                ->exists(), false);

            if ($already) {
                $this->line('Вже надіслано: ' . $label);

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line('[dry-run] Надіслали б: ' . $label);

                continue;
            }

            $status = 'sent';
            $error = null;

            try {
                Mail::to($ownerEmail)->send(new ProRenewalReminder($profile, $subscription, $stage, $manualMode));
                $sent++;
                $this->info('Надіслано: ' . $label);
            } catch (\Throwable $e) {
                $status = 'failed';
                $error = $e->getMessage();
                $this->error('Помилка: ' . $label . ' — ' . $e->getMessage());
                Log::warning('PRO renewal reminder failed to send.', [
                    'subscription_id' => $subscription->id,
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
        }

        $this->info("Готово. Надіслано листів: {$sent}.");

        return self::SUCCESS;
    }
}
