<?php

namespace App\Console\Commands;

use App\Mail\TopProfileOutreach;
use App\Models\EmailLog;
use App\Models\Profile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Аутріч великим незаявленим профілям: їхні цифри з каталогу + claim в один
 * клік. Сегмент — ≥N відгуків, є email, не заявлені, без opt-out, і адресі
 * нічого не слали останні --quiet-days. Один лист на email назавжди (дедуп
 * по обох темах). A/B тема — за парністю id профілю (utm_content = варіант).
 */
class TopProfilesOutreach extends Command
{
    protected $signature = 'dovira:outreach:top-profiles
        {--reviews-min=20 : Мінімум відгуків у профілю}
        {--quiet-days=7 : Пропускати email, якому будь-що слали за останні N днів}
        {--limit=60 : Максимум листів за запуск}
        {--sleep=400 : Пауза між листами, мс}
        {--dry-run : Показати, кому що піде, без відправки}';

    protected $description = 'Аутріч великим незаявленим профілям: цифри з каталогу + підтвердження в один клік';

    public function handle(): int
    {
        // Підписані лінки (claim, відписка) мають вести на публічний домен
        // навіть при запуску з локальної машини (APP_URL=127.0.0.1).
        $public = rtrim((string) config('outreach.public_url', 'https://mydovira.com'), '/');
        URL::forceRootUrl($public);
        if (str_starts_with($public, 'https://')) {
            URL::forceScheme('https');
        }

        $reviewsMin = max(1, (int) $this->option('reviews-min'));
        $quietDays = max(0, (int) $this->option('quiet-days'));
        $limit = max(1, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep'));
        $dryRun = (bool) $this->option('dry-run');

        $candidates = Profile::query()
            ->where('status', 'active')->where('is_published', true)->where('show_in_catalog', true)
            ->whereNull('owner_user_id')
            ->whereNotNull('email')->where('email', '!=', '')
            ->where('reviews_count', '>=', $reviewsMin)
            ->orderByDesc('reviews_count')
            ->get();

        $this->info("Кандидатів (≥{$reviewsMin} відгуків, незаявлені, з email): {$candidates->count()}. Ліміт: {$limit}.");

        $sent = 0;
        $skipped = ['opt_out' => 0, 'already' => 0, 'quiet' => 0, 'bad_email' => 0];

        foreach ($candidates as $profile) {
            if ($sent >= $limit) {
                break;
            }

            $email = mb_strtolower(trim((string) $profile->email));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped['bad_email']++;

                continue;
            }
            if (! empty(data_get($profile->notification_preferences, 'outreach_opted_out'))) {
                $skipped['opt_out']++;

                continue;
            }

            // Постійний дедуп: цей лист (будь-який варіант теми) — один раз на адресу.
            $already = (bool) rescue(fn (): bool => EmailLog::query()
                ->whereRaw('LOWER(TRIM(to_email)) = ?', [$email])
                ->where(function ($q): void {
                    $q->where('subject', 'like', 'Хто керує сторінкою%')
                        ->orWhere('subject', 'like', '%на DOVIRA — а сторінкою ніхто не керує');
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

            $facts = $this->facts($profile);
            $variant = $profile->id % 2 === 0 ? 'a' : 'b';
            $subject = TopProfileOutreach::subjectFor($profile, $facts['reviews'], $variant);

            $label = sprintf(
                '[%s] %s — %s | %d відг, %.1f%s → %s',
                $variant,
                $profile->name,
                $profile->city,
                $facts['reviews'],
                $facts['rating'],
                $facts['rank'] !== null ? ", №{$facts['rank']}/{$facts['total']} у «{$facts['category']}»" : '',
                $email
            );

            if ($dryRun) {
                $this->line('[dry-run] ' . $label);
                $sent++;

                continue;
            }

            $utm = http_build_query([
                'utm_source' => (string) config('outreach.utm.source', 'outreach'),
                'utm_medium' => 'email',
                'utm_campaign' => 'top-profiles',
                'utm_content' => 'tp1_' . $variant,
            ]);
            $profileUrl = $public . route('profile.show', ['slug' => $profile->slug], false) . '?' . $utm;

            $mailable = new TopProfileOutreach(
                $profile,
                $facts,
                $variant,
                URL::temporarySignedRoute('profile.claim-link', now()->addDays(30), ['profile' => $profile->id]),
                $profileUrl,
                URL::signedRoute('review-outreach.unsubscribe', ['profile' => $profile->id]),
            );

            $status = 'sent';
            $error = null;
            try {
                Mail::mailer((string) (config('outreach.mailer') ?: config('mail.default')))->to($email)->send($mailable);
                $this->info('Надіслано: ' . $label);
                $sent++;
            } catch (\Throwable $e) {
                $status = 'failed';
                $error = $e->getMessage();
                $this->error('Помилка: ' . $label . ' — ' . $e->getMessage());
                Log::warning('Top-profiles outreach failed.', ['profile_id' => $profile->id, 'error' => $e->getMessage()]);
            }

            rescue(fn () => EmailLog::create([
                'user_id' => null,
                'to_email' => $email,
                'subject' => $subject,
                'status' => $status,
                'error_message' => $error,
                'sent_at' => $status === 'sent' ? now() : null,
            ]));

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->info(sprintf(
            'Готово: %d %s | пропущено — вже отримували: %d, тиша: %d, opt-out: %d, битий email: %d',
            $sent,
            $dryRun ? 'у сегменті (dry-run)' : 'надіслано',
            $skipped['already'],
            $skipped['quiet'],
            $skipped['opt_out'],
            $skipped['bad_email']
        ));

        return self::SUCCESS;
    }

    /**
     * Чесні факти з НАШОГО каталогу: відгуки, рейтинг, основна категорія,
     * місто і місце за кількістю відгуків серед профілів тієї ж категорії
     * у тому ж місті.
     *
     * @return array{reviews:int, rating:float, category:?string, city:?string, rank:?int, total:?int}
     */
    private function facts(Profile $profile): array
    {
        $categoryId = DB::table('profile_category')
            ->where('profile_id', $profile->id)
            ->orderByDesc('is_primary')
            ->value('category_id');
        $categoryName = $categoryId ? DB::table('categories')->where('id', $categoryId)->value('name') : null;

        $rank = null;
        $total = null;
        if ($categoryId && filled($profile->city)) {
            $peers = Profile::query()
                ->where('status', 'active')->where('is_published', true)->where('show_in_catalog', true)
                ->where('city', $profile->city)
                ->whereIn('id', fn ($q) => $q->select('profile_id')->from('profile_category')->where('category_id', $categoryId))
                ->orderByDesc('reviews_count')->orderBy('id')
                ->pluck('id');
            $pos = $peers->search($profile->id);
            if ($pos !== false) {
                $rank = $pos + 1;
                $total = $peers->count();
            }
        }

        return [
            'reviews' => (int) $profile->reviews_count,
            'rating' => (float) $profile->rating_avg,
            'category' => $categoryName ? (string) $categoryName : null,
            'city' => filled($profile->city) ? (string) $profile->city : null,
            'rank' => $rank,
            'total' => $total,
        ];
    }
}
