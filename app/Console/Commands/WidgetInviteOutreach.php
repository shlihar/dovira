<?php

namespace App\Console\Commands;

use App\Mail\WidgetInvite;
use App\Models\EmailLog;
use App\Models\Profile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Запрошення поставити віджет: заявленим власникам, у яких є сайт, але
 * віджет ще не зафіксовано в подіях. Лист містить готовий код під профіль.
 * Один лист на профіль назавжди (дедуп по темі, тема містить домен сайту).
 */
class WidgetInviteOutreach extends Command
{
    protected $signature = 'dovira:outreach:widget-invite
        {--quiet-days=2 : Пропускати email, якому будь-що слали за останні N днів}
        {--limit=50 : Максимум листів за запуск}
        {--dry-run : Показати, кому що піде, без відправки}';

    protected $description = 'Лист заявленим власникам із сайтом: готовий код віджета + знижка 20%';

    private const SKIP_EMAILS = ['shlikhar22@gmail.com', 'reviews-debug@example.com', 'test@example.com'];

    public function handle(): int
    {
        URL::forceRootUrl('https://mydovira.com');
        URL::forceScheme('https');

        $quietDays = max(0, (int) $this->option('quiet-days'));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        // Профілі, де віджет уже працює (є події) — не турбуємо.
        $withWidget = DB::table('profile_events')
            ->where('utm_source', 'dovira_widget')
            ->distinct()->pluck('profile_id')->flip();

        $profiles = Profile::query()
            ->whereNotNull('owner_user_id')
            ->where('status', 'active')
            ->whereNotNull('website')->where('website', '!=', '')
            ->with('owner')
            ->orderByDesc('reviews_count')
            ->get();

        $this->info("Заявлених із сайтом: {$profiles->count()}. Ліміт: {$limit}.");

        $sent = 0;
        $skipped = ['widget' => 0, 'test' => 0, 'already' => 0, 'quiet' => 0, 'opt_out' => 0, 'bad' => 0, 'same_owner' => 0];
        // Одна людина може мати кілька профілів з тим самим сайтом — пишемо раз.
        $seenEmails = [];

        foreach ($profiles as $profile) {
            if ($sent >= $limit) {
                break;
            }

            if (isset($withWidget[$profile->id])) {
                $skipped['widget']++;

                continue;
            }

            $email = mb_strtolower(trim((string) ($profile->owner?->email ?? '')));
            $host = (string) parse_url((string) $profile->website, PHP_URL_HOST);
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $host === '') {
                $skipped['bad']++;

                continue;
            }
            if (in_array($email, self::SKIP_EMAILS, true)) {
                $skipped['test']++;

                continue;
            }
            if (isset($seenEmails[$email])) {
                $skipped['same_owner']++;

                continue;
            }

            $prefs = (array) ($profile->notification_preferences ?? []);
            if (($prefs['email_enabled'] ?? true) === false || ! empty($prefs['outreach_opted_out'])) {
                $skipped['opt_out']++;

                continue;
            }

            $subject = WidgetInvite::subjectFor($profile);
            $already = (bool) rescue(fn (): bool => EmailLog::query()
                ->whereRaw('LOWER(TRIM(to_email)) = ?', [$email])
                ->where('subject', $subject)
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

            $label = sprintf('%s → %s | сайт %s | %d відг', $profile->name, $email, $host, (int) $profile->reviews_count);

            $seenEmails[$email] = true;

            if ($dryRun) {
                $this->line('[dry-run] ' . $label);
                $sent++;

                continue;
            }

            $status = 'sent';
            $error = null;
            try {
                Mail::to($email)->send(new WidgetInvite(
                    $profile,
                    (int) $profile->reviews_count,
                    (float) $profile->rating_avg,
                    route('pro.account', ['profile' => $profile->id]),
                ));
                $this->info('Надіслано: ' . $label);
                $sent++;
            } catch (\Throwable $e) {
                $status = 'failed';
                $error = $e->getMessage();
                $this->error('Помилка: ' . $label . ' — ' . $e->getMessage());
                Log::warning('Widget invite failed.', ['profile_id' => $profile->id, 'error' => $e->getMessage()]);
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
            'Готово: %d %s | пропущено — віджет уже стоїть: %d, інший профіль того самого власника: %d, вже отримували: %d, тиша: %d, відписки: %d, тест: %d, без даних: %d',
            $sent,
            $dryRun ? 'у сегменті (dry-run)' : 'надіслано',
            $skipped['widget'], $skipped['same_owner'], $skipped['already'], $skipped['quiet'], $skipped['opt_out'], $skipped['test'], $skipped['bad']
        ));

        return self::SUCCESS;
    }
}
