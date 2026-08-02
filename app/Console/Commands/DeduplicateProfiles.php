<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Services\ProfileReviewStatsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Знаходить дублі профілів і зливає кожну групу в один «головний».
 *
 * Дубль = однакова нормалізована назва І (те саме місто АБО той самий телефон).
 * Цей ключ навмисно не зливає різні бізнеси зі спільним реєстровим/0-800
 * телефоном (у них різні назви) і не чіпає філії нацмереж з різними назвами.
 *
 * «Головний» у групі: профіль із власником/PRO/claim (якщо один такий), інакше
 * — з найбільшою кількістю відгуків. Відгуки, ліди й джерела даних програшних
 * профілів переносяться на головного, далі програшні видаляються (каскад
 * прибирає категорії/події). Групи з кількома власниками пропускаються.
 */
class DeduplicateProfiles extends Command
{
    protected $signature = 'dovira:dedupe-profiles
        {--dry-run : Порахувати й показати групи без змін}
        {--limit=0 : Обмежити кількість груп (для тесту)}';

    protected $description = 'Зливає дублі профілів (однакова назва + місто/телефон) у профіль з найбільшою кількістю відгуків.';

    public function handle(ProfileReviewStatsService $stats): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $this->info('Завантаження профілів…');
        // Легкі stdClass-рядки замість Eloquent — інакше 30k моделей = OOM.
        $profiles = DB::table('profiles')
            ->select(['id', 'name', 'city', 'phone', 'reviews_count', 'google_reviews_count', 'owner_user_id', 'is_pro'])
            ->get();

        $claimedIds = DB::table('profile_claims')->distinct()->pluck('profile_id')->flip();

        // Дубль = та сама ІДЕНТИФІКУЮЧА назва (без генеричних слів на кшталт
        // «нотаріус»/«адвокат») + те саме місто. Тільки в межах міста, щоб не
        // зливати реальні філії мереж у різних містах. Генеричні назви
        // (без прізвища/бренду) не чіпаємо взагалі.
        $groupsByKey = [];
        foreach ($profiles as $p) {
            $nameKey = $this->identifyingNameKey((string) $p->name);
            if ($nameKey === '') {
                continue; // генерична/непізнавана назва
            }
            $cityKey = $this->cityKey((string) $p->city);
            $key = $nameKey.'|'.$cityKey;
            $groupsByKey[$key][] = $p;
        }

        $byId = $profiles->keyBy('id');
        $clusters = [];
        foreach ($groupsByKey as $members) {
            if (count($members) > 1) {
                $clusters[] = array_map(fn ($p) => (int) $p->id, $members);
            }
        }

        $this->info('Знайдено груп дублів: '.count($clusters));

        $plannedDelete = 0;
        $plannedMoveReviews = 0;
        $skippedMultiOwner = 0;
        $deletedIds = [];
        $shown = 0;

        // Бекап видалених профілів.
        $backupPath = null;
        $fh = null;
        if (! $dryRun) {
            $dir = storage_path('app/profile-dedupe-backups');
            @mkdir($dir, 0775, true);
            $backupPath = $dir.'/dedupe-'.now()->format('Ymd-His').'.jsonl';
            $fh = fopen($backupPath, 'w');
        }

        $groupIndex = 0;
        foreach ($clusters as $ids) {
            if ($limit > 0 && $groupIndex >= $limit) {
                break;
            }
            $groupIndex++;

            $members = array_map(fn ($id) => $byId[$id], $ids);

            // Захищені (власник/PRO/claim) — їх не видаляємо.
            $owned = array_values(array_filter($members, fn ($p) =>
                $p->owner_user_id !== null || (bool) $p->is_pro || isset($claimedIds[$p->id])));

            if (count($owned) > 1) {
                $skippedMultiOwner++;
                if ($dryRun && $shown < 15) {
                    $this->warn('  ПРОПУСК (кілька власників): '.implode(', ', array_map(fn ($p) => '#'.$p->id.' '.$p->name, $members)));
                }
                continue;
            }

            // Головний: єдиний власник, інакше макс. відгуків → google-відгуків → менший id.
            $keeper = count($owned) === 1 ? $owned[0] : $this->pickKeeper($members);
            $losers = array_values(array_filter($members, fn ($p) => (int) $p->id !== (int) $keeper->id));

            $moveReviews = array_sum(array_map(fn ($p) => (int) $p->reviews_count, $losers));
            $plannedDelete += count($losers);
            $plannedMoveReviews += $moveReviews;

            if ($dryRun && $shown < 25) {
                $shown++;
                $this->line(sprintf('  [%s] ЗАЛИШИТИ #%d «%s» (%s, відг %d)%s',
                    $keeper->city ?: '—', $keeper->id, mb_substr($keeper->name, 0, 40),
                    $keeper->phone ?: 'без тел', (int) $keeper->reviews_count,
                    count($owned) === 1 ? ' [власник/PRO]' : ''));
                foreach ($losers as $l) {
                    $this->line(sprintf('        видалити #%d «%s» (%s, відг %d)', $l->id, mb_substr($l->name, 0, 40), $l->city ?: '—', (int) $l->reviews_count));
                }
            }

            if ($dryRun) {
                continue;
            }

            $loserIds = array_map(fn ($p) => (int) $p->id, $losers);

            DB::transaction(function () use ($keeper, $loserIds, $fh): void {
                foreach (DB::table('profiles')->whereIn('id', $loserIds)->get() as $row) {
                    fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE)."\n");
                }
                // Переносимо цінні дані на головного.
                DB::table('profile_reviews')->whereIn('profile_id', $loserIds)->update(['profile_id' => $keeper->id]);
                DB::table('profile_leads')->whereIn('profile_id', $loserIds)->update(['profile_id' => $keeper->id]);
                DB::table('profile_data_sources')->whereIn('profile_id', $loserIds)->update(['profile_id' => $keeper->id]);
                // Видаляємо програшні (каскад прибере категорії/події/фаворити).
                Profile::whereIn('id', $loserIds)->delete();
            });

            $deletedIds = array_merge($deletedIds, $loserIds);
            $stats->recalculateForProfileId((int) $keeper->id);
        }

        if ($fh) {
            fclose($fh);
        }

        $this->newLine();
        $this->table(['Метрика', 'Значення'], [
            ['Груп дублів', count($clusters)],
            ['Пропущено (кілька власників)', $skippedMultiOwner],
            ['Профілів під видалення', $plannedDelete],
            ['Відгуків до перенесення', $plannedMoveReviews],
            ['Фактично видалено', $dryRun ? 0 : count($deletedIds)],
        ]);

        if ($dryRun) {
            $this->line('(dry-run) нічого не змінено.');
        } elseif ($backupPath) {
            $this->info("Бекап видалених профілів: {$backupPath}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, \stdClass>  $members
     */
    private function pickKeeper(array $members): \stdClass
    {
        usort($members, function ($a, $b) {
            return [(int) $b->reviews_count, (int) $b->google_reviews_count, -(int) $a->id]
                <=> [(int) $a->reviews_count, (int) $a->google_reviews_count, -(int) $b->id];
        });

        return $members[0];
    }

    /**
     * Лише слова-професії/статуси, що зазвичай стоять префіксом перед іменем.
     * Їх прибираємо, а ІНІЦІАЛИ/по-батькові ЛИШАЄМО — саме вони відрізняють
     * «Мороз Р. Д.» від «Мороз В. П.» (це різні люди, зливати не можна).
     */
    private const GENERIC_TOKENS = [
        'адвокат', 'юрист', 'нотариус', 'приватнии', 'державнии', 'приват', 'адвокатура',
    ];

    /**
     * Ключ назви: усі токени (включно з ініціалами й по-батькові) окрім
     * слів-професій. Порожньо, якщо не лишилось жодного «слова» (лише
     * категорія/ініціали) — такі непізнавані назви до дедупу не беремо.
     */
    private function identifyingNameKey(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = strtr($s, [
            'і' => 'и', 'ї' => 'и', 'й' => 'и', 'ы' => 'и',
            'є' => 'е', 'э' => 'е', 'ё' => 'е', 'ґ' => 'г',
            'ь' => '', 'ъ' => '', '’' => '', "'" => '', '`' => '', '«' => ' ', '»' => ' ', '"' => ' ',
        ]);
        $s = (string) preg_replace('/[^а-яa-z0-9]+/u', ' ', $s);
        $tokens = array_values(array_filter(
            preg_split('/\s+/u', trim($s)) ?: [],
            fn (string $t): bool => $t !== '' && ! in_array($t, self::GENERIC_TOKENS, true)
        ));

        // Має лишитись хоч одне повноцінне слово (прізвище/бренд, ≥3 літери),
        // інакше це «Нотаріус» / «Приватний нотаріус» / самі ініціали — пропуск.
        $hasWord = false;
        foreach ($tokens as $t) {
            if (mb_strlen($t) >= 3) {
                $hasWord = true;
                break;
            }
        }
        if (! $hasWord) {
            return '';
        }

        sort($tokens); // порядок слів не важливий

        return implode('|', $tokens);
    }

    /**
     * Нормалізує назву міста, зводячи рос/укр варіанти до одного ключа
     * («Львов»→«Львів», «Одесса»→«Одеса»), щоб дублі з різним написанням міста
     * все ж злилися.
     */
    private function cityKey(string $city): string
    {
        $s = mb_strtolower(trim($city));
        $synonyms = [
            'львов' => 'львів', 'киев' => 'київ', 'одесса' => 'одеса',
            'николаев' => 'миколаїв', 'ровно' => 'рівне', 'запорожье' => 'запоріжжя',
            'чернигов' => 'чернігів', 'винница' => 'вінниця', 'днепр' => 'дніпро',
            'харьков' => 'харків', 'житомир' => 'житомир', 'кривои рог' => 'кривий ріг',
            'ивано франковск' => 'івано-франківськ', 'хмельницкии' => 'хмельницький',
        ];
        $s = $synonyms[$s] ?? $s;
        $s = strtr($s, ['і' => 'и', 'ї' => 'и', 'й' => 'и', 'є' => 'е', 'ґ' => 'г', 'ь' => '']);

        return (string) preg_replace('/[^а-яa-z0-9]+/u', '', $s);
    }

    private function phoneKey(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);

        return strlen($digits) >= 9 ? substr($digits, -9) : '';
    }
}
