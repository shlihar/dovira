<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Rating\RatingRecalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ховає дублі відгуків, яких НЕ бачить dovira:reviews:dedupe.
 *
 * Та команда звіряє перші 120 символів тексту, тому ловить лише точні
 * передруки. А імпорт із Google приніс ще й пари «той самий відгук
 * російською + машинний переклад українською»: текст різний, отже хеш
 * різний, отже дедуп мовчить, а профіль отримує подвоєну оцінку.
 *
 * Ключ: Google дозволяє лише ОДИН відгук на акаунт про одне місце, тому
 * повтор автора на тому самому профілі — завжди артефакт імпорту.
 * Але той самий автор міг написати й два РІЗНІ відгуки (наприклад, окремо
 * про клініку і окремо про лікарку), тому додатково звіряємо схожість
 * текстів: ідентичні або ≥45% — дубль, нижче — лишаємо недоторканим.
 * На вибірці з 200 груп межа розділила переклади (67-70%) і справді різні
 * відгуки (14-23%) без жодного перетину.
 *
 * Ховаємо (status=hidden), а не видаляємо: рішення оборотне, а сам рядок
 * лишається для розбору.
 */
final class HideTranslatedDuplicateReviews extends Command
{
    protected $signature = 'dovira:reviews:hide-translated-dupes
        {--similarity=45 : Поріг схожості у відсотках, від якого вважаємо дублем}
        {--dry-run : Показати, що сховали б, без змін}';

    protected $description = 'Ховає дублі-переклади відгуків (той самий автор і оцінка, текст збігається за змістом)';

    public function handle(RatingRecalculator $recalculator): int
    {
        $threshold = (float) $this->option('similarity');
        $dryRun = (bool) $this->option('dry-run');

        $rows = DB::select('SELECT id, profile_id, author_name, rating, status, body FROM (
                SELECT pr.id, pr.profile_id, pr.author_name, pr.rating, pr.status, pr.body,
                       COUNT(*) OVER (PARTITION BY pr.profile_id, pr.author_name, pr.rating) c
                FROM profile_reviews pr
                WHERE pr.status = "published" AND pr.source = "google_import"
                  AND pr.author_name IS NOT NULL AND pr.author_name != ""
                  AND pr.author_name NOT LIKE "Користувач Google%"
                  AND pr.author_name NOT LIKE "Клієнт %"
                  AND pr.author_name NOT LIKE "A Google%"
                  AND pr.body IS NOT NULL AND pr.body != ""
            ) t WHERE c > 1 ORDER BY profile_id, author_name, rating, id');

        $groups = [];
        foreach ($rows as $row) {
            $groups[$row->profile_id.'|'.$row->author_name.'|'.$row->rating][] = $row;
        }

        $toHide = [];
        $kept = 0;

        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }

            $keepText = $this->normalize($group[0]->body);

            foreach (array_slice($group, 1) as $row) {
                $text = $this->normalize($row->body);

                if ($text === $keepText) {
                    $toHide[] = $row;

                    continue;
                }

                similar_text($keepText, $text, $percent);

                if ($percent >= $threshold) {
                    $toHide[] = $row;
                } else {
                    $kept++;
                }
            }
        }

        $this->info('Груп із повтором автора: '.count($groups));
        $this->info('Під приховування: '.count($toHide));
        $this->info('Лишаю як справді різні відгуки: '.$kept);

        if ($toHide === []) {
            return self::SUCCESS;
        }

        if ($dryRun) {
            foreach (array_slice($toHide, 0, 10) as $row) {
                $this->line(sprintf('  [dry-run] #%d профіль %d | %s (%d★)', $row->id, $row->profile_id, $row->author_name, $row->rating));
            }

            return self::SUCCESS;
        }

        $dir = storage_path('app/review-analysis/backup');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $log = fopen($dir.'/prykhovani-pereklady.jsonl', 'w');
        foreach ($toHide as $row) {
            fwrite($log, json_encode([
                'id' => (int) $row->id,
                'profile_id' => (int) $row->profile_id,
                'status_bulo' => $row->status,
                'author' => $row->author_name,
                'rating' => (int) $row->rating,
                'body' => $row->body,
            ], JSON_UNESCAPED_UNICODE)."\n");
        }
        fclose($log);

        $hidden = 0;
        foreach (array_chunk(array_map(fn ($r): int => (int) $r->id, $toHide), 500) as $chunk) {
            $hidden += DB::table('profile_reviews')->whereIn('id', $chunk)->update([
                'status' => 'hidden',
                'moderation_reason' => 'duplicate_translation',
                'moderation_note' => 'Дубль того самого відгуку (машинний переклад або повторний імпорт).',
                'updated_at' => now(),
            ]);
        }

        $profileIds = array_values(array_unique(array_map(fn ($r): int => (int) $r->profile_id, $toHide)));

        $this->info('Сховано рядків: '.$hidden);
        $this->info('Перераховую лічильники для '.count($profileIds).' профілів…');

        // Рахуємо так само, як dovira:reviews:dedupe, щоб у полях
        // reviews_count і rating_avg не зʼявилося двох різних семантик.
        foreach ($profileIds as $profileId) {
            $stats = DB::table('profile_reviews')
                ->where('profile_id', $profileId)
                ->where('status', 'published')
                ->selectRaw('COUNT(*) c, AVG(rating) a')
                ->first();

            DB::table('profiles')->where('id', $profileId)->update([
                'reviews_count' => (int) $stats->c,
                'rating_avg' => round((float) $stats->a, 2),
            ]);
        }

        $this->info('Готово. Лог для відкату: '.$dir.'/prykhovani-pereklady.jsonl');

        return self::SUCCESS;
    }

    private function normalize(?string $body): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $body)));
    }
}
