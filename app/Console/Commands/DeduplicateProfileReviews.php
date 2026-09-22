<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Profile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Прибирає дублікати імпортованих відгуків.
 *
 * Причина появи: Google віддає ВІДНОСНУ дату («8 місяців тому»), яка на
 * кожному імпорті перетворюється на іншу абсолютну. Через це й
 * external_review_hash виходить різний, і fallback-дедуп по даті не
 * спрацьовує — той самий відгук лягає ще раз (кейс 14.09.2026:
 * 6 999 зайвих рядків у 5 528 групах).
 *
 * Ключ дубля: profile_id + автор + рейтинг + перші 120 символів тексту.
 * Лишаємо запис із офіційною відповіддю, інакше найстаріший (min id) —
 * на нього могли посилатися, і він має найдовшу історію.
 */
final class DeduplicateProfileReviews extends Command
{
    protected $signature = 'dovira:reviews:dedupe
        {--profile-id= : Лише один профіль}
        {--limit=0 : Максимум груп за запуск (0 — усі)}
        {--dry-run : Показати, що видалили б, без змін}';

    protected $description = 'Видаляє дублікати імпортованих відгуків і перераховує рейтинги профілів';

    public function handle(): int
    {
        $profileId = $this->option('profile-id') !== null ? (int) $this->option('profile-id') : null;
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $sql = 'SELECT profile_id, author_name, rating, LEFT(REPLACE(REPLACE(REPLACE(body,"\r"," "),"\n"," "),"  "," "),120) b, COUNT(*) c
                FROM profile_reviews
                WHERE body IS NOT NULL AND body != ""'
            . ($profileId ? ' AND profile_id = ' . $profileId : '')
            . ' GROUP BY profile_id, author_name, rating, b
                HAVING COUNT(*) > 1
                ORDER BY c DESC'
            . ($limit > 0 ? ' LIMIT ' . $limit : '');

        $groups = DB::select($sql);

        if ($groups === []) {
            $this->info('Дублікатів не знайдено.');

            return self::SUCCESS;
        }

        $this->info('Груп дублів: ' . count($groups) . ' | зайвих рядків: '
            . array_sum(array_map(fn ($g): int => (int) $g->c - 1, $groups)));

        $deleted = 0;
        $touchedProfiles = [];

        foreach ($groups as $group) {
            $ids = DB::table('profile_reviews')
                ->where('profile_id', $group->profile_id)
                ->where('author_name', $group->author_name)
                ->where('rating', $group->rating)
                ->whereRaw('LEFT(REPLACE(REPLACE(REPLACE(body,"\r"," "),"\n"," "),"  "," "),120) = ?', [$group->b])
                ->orderBy('id')
                ->pluck('id')
                ->all();

            if (count($ids) < 2) {
                continue;
            }

            // Пріоритет на збереження — запис з офіційною відповіддю власника.
            $withReply = DB::table('review_replies')
                ->whereIn('profile_review_id', $ids)
                ->where('is_official', true)
                ->orderBy('profile_review_id')
                ->value('profile_review_id');

            $keep = (int) ($withReply ?: $ids[0]);
            $drop = array_values(array_diff($ids, [$keep]));

            if ($drop === []) {
                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    '[dry-run] профіль %d | %s (%d★): лишаю #%d, видаляю %s',
                    $group->profile_id,
                    $group->author_name,
                    $group->rating,
                    $keep,
                    implode(', #', $drop)
                ));
                $deleted += count($drop);

                continue;
            }

            DB::transaction(function () use ($drop, &$deleted): void {
                DB::table('review_replies')->whereIn('profile_review_id', $drop)->delete();
                DB::table('profile_reviews')->whereIn('id', $drop)->delete();
                $deleted += count($drop);
            });

            $touchedProfiles[(int) $group->profile_id] = true;
        }

        if (! $dryRun && $touchedProfiles !== []) {
            $this->info('Перераховую лічильники для ' . count($touchedProfiles) . ' профілів…');
            foreach (array_keys($touchedProfiles) as $pid) {
                $count = (int) DB::table('profile_reviews')
                    ->where('profile_id', $pid)->where('status', 'published')->count();
                $avg = (float) DB::table('profile_reviews')
                    ->where('profile_id', $pid)->where('status', 'published')->avg('rating');

                Profile::query()->where('id', $pid)->update([
                    'reviews_count' => $count,
                    'rating_avg' => round($avg, 2),
                ]);
            }
        }

        $this->info($dryRun
            ? "Разом видалили б: {$deleted}"
            : "Готово. Видалено дублів: {$deleted}. Профілів оновлено: " . count($touchedProfiles));

        return self::SUCCESS;
    }
}
