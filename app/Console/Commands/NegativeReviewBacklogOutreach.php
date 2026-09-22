<?php

namespace App\Console\Commands;

use App\Models\EmailLog;
use App\Models\Profile;
use App\Models\ProfileReview;
use App\Services\Notifications\NegativeReviewAlertMailer;
use App\Services\ProfileAnalyticsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Разова/періодична аутріч-кампанія «свіжий негатив + перегляди»:
 * незаявлені профілі з email, у яких є опублікований негативний відгук
 * (включно з імпортованими — на відміну від live-тригера) і реальні
 * перегляди сторінки за 30 днів. Найгарячіший сегмент продажу PRO:
 * лист показує сам відгук + скільки людей його бачать.
 *
 * Гарди мейлера (прапорець, opt-out, тротлінг 30 днів по темі) працюють;
 * додатково не чіпаємо email, якому будь-що слали останні N днів, щоб
 * не перетинатися зі щоденним диспетчером dovira:outreach:daily.
 */
class NegativeReviewBacklogOutreach extends Command
{
    protected $signature = 'dovira:outreach:negative-backlog
        {--days=60 : Негативний відгук не старіший за N днів}
        {--views-min=3 : Мінімум переглядів профілю за 30 днів}
        {--limit=40 : Максимум листів за запуск}
        {--quiet-days=7 : Пропускати email, якому щось слали за останні N днів}
        {--dry-run : Показати сегмент без відправки}';

    protected $description = 'Аутріч по незаявлених профілях зі свіжим негативним відгуком і переглядами';

    public function handle(NegativeReviewAlertMailer $mailer): int
    {
        $days = max(1, (int) $this->option('days'));
        $viewsMin = max(0, (int) $this->option('views-min'));
        $limit = max(1, (int) $this->option('limit'));
        $quietDays = max(0, (int) $this->option('quiet-days'));
        $since = now()->subDays($days);

        // Профілі-кандидати: незаявлені, з email, зі свіжим негативом.
        $candidates = Profile::query()
            ->where('status', 'active')
            ->where('is_published', true)
            ->where('show_in_catalog', true)
            ->whereNull('owner_user_id')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereHas('reviews', function ($q) use ($since): void {
                $q->where('status', 'published')
                    ->where('rating', '<=', 2)
                    ->where(function ($qq) use ($since): void {
                        $qq->where('external_review_date', '>=', $since)
                            ->orWhere(function ($qqq) use ($since): void {
                                $qqq->whereNull('external_review_date')->where('created_at', '>=', $since);
                            });
                    });
            })
            ->get(['id', 'name', 'slug', 'email', 'notification_preferences']);

        if ($candidates->isEmpty()) {
            $this->info('Кандидатів немає.');

            return self::SUCCESS;
        }

        // Перегляди за 30 днів одним запитом — сортуємо від найгарячіших.
        $views = DB::table('profile_events')
            ->whereIn('profile_id', $candidates->pluck('id'))
            ->where('event_type', ProfileAnalyticsService::EVENT_PROFILE_VIEW)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('profile_id, count(*) c')
            ->groupBy('profile_id')
            ->pluck('c', 'profile_id');

        $candidates = $candidates
            ->map(function (Profile $p) use ($views): Profile {
                $p->setAttribute('views_30d', (int) ($views[$p->id] ?? 0));

                return $p;
            })
            ->filter(fn (Profile $p): bool => (int) $p->views_30d >= $viewsMin)
            ->sortByDesc('views_30d')
            ->values();

        $this->info("Сегмент після фільтра переглядів (≥{$viewsMin}): {$candidates->count()} профілів. Ліміт: {$limit}.");

        $sent = 0;
        $skipped = 0;

        foreach ($candidates as $profile) {
            if ($sent >= $limit) {
                break;
            }

            // Постійний дедуп кампанії: один лист «негативний відгук» на email
            // НАЗАВЖДИ. Без цього після спливання 30-денного тротлінгу мейлера
            // той самий профіль отримував би той самий лист щомісяця.
            $alreadyPitched = (bool) rescue(fn (): bool => EmailLog::query()
                ->where('to_email', $profile->email)
                ->where(function ($q): void {
                    $q->where('subject', 'like', 'Негативний відгук про%')
                        ->orWhere('subject', 'like', 'Про ваш бізнес%');
                })
                ->exists(), false);
            if ($alreadyPitched) {
                $skipped++;

                continue;
            }

            // Тиша: цьому email нічого не слали останні N днів (будь-яка тема).
            if ($quietDays > 0) {
                $busy = (bool) rescue(fn (): bool => EmailLog::query()
                    ->where('to_email', $profile->email)
                    ->where('created_at', '>=', now()->subDays($quietDays))
                    ->exists(), false);
                if ($busy) {
                    $skipped++;

                    continue;
                }
            }

            // Найсвіжіший негатив профілю — саме його показуємо в листі.
            $review = ProfileReview::query()
                ->where('profile_id', $profile->id)
                ->where('status', 'published')
                ->where('rating', '<=', 2)
                ->orderByRaw('COALESCE(external_review_date, created_at) DESC')
                ->first();

            if (! $review) {
                continue;
            }

            $label = sprintf('%s (%d переглядів/30д) → %s', $profile->name, (int) $profile->views_30d, $profile->email);

            if ($this->option('dry-run')) {
                $this->line('[dry-run] ' . $label);
                $sent++;

                continue;
            }

            if ($mailer->sendOutreachFor($profile, $review)) {
                $this->info('Надіслано: ' . $label);
                $sent++;
            } else {
                $this->line('Пропуск (гарди мейлера): ' . $label);
                $skipped++;
            }
        }

        $this->info("Готово: {$sent} " . ($this->option('dry-run') ? 'у сегменті (dry-run)' : 'надіслано') . ", пропущено {$skipped}.");

        return self::SUCCESS;
    }
}
