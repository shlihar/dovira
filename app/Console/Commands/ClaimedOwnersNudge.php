<?php

namespace App\Console\Commands;

use App\Mail\ClaimedOwnerNudge;
use App\Models\EmailLog;
use App\Models\Profile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Дотик власникам claimed-профілів без PRO: сторінку вже показують, а
 * відгуки стоять без відповіді компанії. Один лист на email назавжди
 * (дедуп по темах ClaimedOwnerNudge::subjectPatterns), не раніше ніж через
 * --quiet-days після будь-якого іншого листа; тестові акаунти виключені.
 */
class ClaimedOwnersNudge extends Command
{
    protected $signature = 'dovira:outreach:claimed-nudge
        {--quiet-days=3 : Пропускати email, якому будь-що слали за останні N днів}
        {--limit=50 : Максимум листів за запуск}
        {--dry-run : Показати, кому що піде, без відправки}';

    protected $description = 'Лист власникам підтверджених профілів без PRO: відгуки без вашої відповіді';

    /** Тестові/внутрішні адреси й назви — ніколи не пишемо. */
    private const SKIP_EMAILS = ['shlikhar22@gmail.com', 'reviews-debug@example.com', 'test@example.com'];

    private const SKIP_NAME_PATTERNS = ['/^тест/iu', '/^ffff$/i', '/debug/i', '/^test/i'];

    public function handle(): int
    {
        URL::forceRootUrl('https://mydovira.com');
        URL::forceScheme('https');

        $quietDays = max(0, (int) $this->option('quiet-days'));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $profiles = Profile::query()
            ->whereNotNull('owner_user_id')
            ->where('status', 'active')
            ->with('owner')
            ->orderByDesc('reviews_count')
            ->get()
            ->filter(fn (Profile $p): bool => ! $p->hasActiveProSubscription());

        $this->info("Підтверджених без PRO: {$profiles->count()}. Ліміт: {$limit}.");

        $sent = 0;
        $skipped = ['test' => 0, 'already' => 0, 'quiet' => 0, 'bad_email' => 0, 'opt_out' => 0];

        foreach ($profiles as $profile) {
            if ($sent >= $limit) {
                break;
            }

            $email = mb_strtolower(trim((string) ($profile->owner?->email ?? '')));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped['bad_email']++;

                continue;
            }
            if (in_array($email, self::SKIP_EMAILS, true) || $this->looksLikeTest($profile->name)) {
                $skipped['test']++;

                continue;
            }

            // Футер листа обіцяє «вимкнути в налаштуваннях сповіщень» — поважаємо.
            $prefs = (array) ($profile->notification_preferences ?? []);
            if (($prefs['email_enabled'] ?? true) === false || ! empty($prefs['outreach_opted_out'])) {
                $skipped['opt_out']++;

                continue;
            }

            $facts = $this->facts($profile);
            $subject = ClaimedOwnerNudge::subjectFor($profile, $facts['reviews']);
            $already = (bool) rescue(fn (): bool => EmailLog::query()
                ->whereRaw('LOWER(TRIM(to_email)) = ?', [$email])
                ->where(function ($q): void {
                    foreach (ClaimedOwnerNudge::subjectPatterns() as $pattern) {
                        $q->orWhere('subject', 'like', $pattern);
                    }
                })
                ->exists(), false);
            if ($already) {
                $skipped['already']++;

                continue;
            }

            if ($quietDays > 0) {
                $busy = (bool) rescue(fn (): bool => EmailLog::query()
                    ->whereRaw('LOWER(TRIM(to_email)) = ?', [$email])
                    ->where('created_at', '>=', now()->subDays($quietDays))
                    ->exists(), false);
                if ($busy) {
                    $skipped['quiet']++;

                    continue;
                }
            }

            $label = sprintf(
                '%s → %s | %d відг, %d без відповіді (%d нег.), %d показів/90д',
                $profile->name,
                $email,
                $facts['reviews'],
                $facts['unanswered'],
                $facts['negative_unanswered'],
                $facts['impressions90']
            );

            if ($dryRun) {
                $this->line('[dry-run] ' . $label);
                $sent++;

                continue;
            }

            $status = 'sent';
            $error = null;
            try {
                Mail::to($email)->send(new ClaimedOwnerNudge(
                    $profile,
                    $facts,
                    route('pro.account', ['tab' => 'reviews', 'profile' => $profile->id]),
                    route('pro.account', ['tab' => 'billing', 'profile' => $profile->id]),
                    route('profile.show', ['slug' => $profile->slug]) . '?utm_source=dovira_email&utm_medium=claimed_nudge',
                ));
                $this->info('Надіслано: ' . $label);
                $sent++;
            } catch (\Throwable $e) {
                $status = 'failed';
                $error = $e->getMessage();
                $this->error('Помилка: ' . $label . ' — ' . $e->getMessage());
                Log::warning('Claimed-nudge email failed.', ['profile_id' => $profile->id, 'error' => $e->getMessage()]);
            }

            rescue(fn () => EmailLog::create([
                'user_id' => $profile->owner_user_id,
                'to_email' => $email,
                'subject' => $subject,
                'status' => $status,
                'error_message' => $error,
                'sent_at' => $status === 'sent' ? now() : null,
            ]));
        }

        $this->info(sprintf(
            'Готово: %d %s | пропущено — тестові: %d, вже отримували: %d, тиша: %d, відписки: %d, битий email: %d',
            $sent,
            $dryRun ? 'у сегменті (dry-run)' : 'надіслано',
            $skipped['test'],
            $skipped['already'],
            $skipped['quiet'],
            $skipped['opt_out'],
            $skipped['bad_email']
        ));

        return self::SUCCESS;
    }

    private function looksLikeTest(string $name): bool
    {
        foreach (self::SKIP_NAME_PATTERNS as $pattern) {
            if (preg_match($pattern, trim($name))) {
                return true;
            }
        }

        return false;
    }

    /** @return array{reviews:int, negative_unanswered:int, unanswered:int, impressions90:int} */
    private function facts(Profile $profile): array
    {
        // «Без відповіді» — саме на DOVIRA (імпорт не переносить відповіді
        // власника з Google), і так само уточнено в тексті листа.
        $published = fn () => $profile->reviews()->where('status', 'published')->doesntHave('officialReply');

        return [
            'reviews' => (int) $profile->reviews_count,
            'negative_unanswered' => (int) $published()->where('rating', '<=', 2)->count(),
            'unanswered' => (int) $published()->count(),
            // Покази = відкриття сторінки + покази картки в каталозі/пошуку:
            // та сама величина, що в кабінеті («показів разом»).
            'impressions90' => (int) DB::table('profile_events')
                ->where('profile_id', $profile->id)
                ->whereIn('event_type', ['profile_view', 'card_impression'])
                ->where('created_at', '>=', now()->subDays(90))
                ->count(),
        ];
    }
}
