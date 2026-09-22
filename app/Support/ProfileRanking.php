<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ЄДИНА політика ранжування профілів у публічних списках.
 *
 * Правила (узгоджені з config/rating.php і публічною методологією
 * /rating-method, яку ми обіцяємо користувачам):
 *
 *  1. Органічна видача сортується ЛИШЕ за популярністю
 *     (profiles.popularity_score — дзеркало profile_ratings, рахується вночі:
 *     перегляди × якість × повнота × залученість × реакція × обсяг).
 *     PRO у цю формулу НЕ входить — «заплатив» не піднімає в органіці.
 *
 *  2. PRO отримує видимість окремим промаркованим блоком «Спонсоровані»
 *     угорі списку, а не бустом усередині органіки. Так ми не продаємо
 *     позицію в чесній видачі — і лишаємо місце під платну рекламу як
 *     окремий продукт.
 *
 *  3. У блоці показуються ВСІ релевантні PRO (один слот на власника, щоб
 *     дублі одного бізнесу не забирали блок; стеля з конфігу — запобіжник,
 *     а не квота). Порядок — за популярністю з денною ротацією: перша
 *     позиція дістається всім по черзі, нікому не гарантована конкретна.
 */
final class ProfileRanking
{
    /** Стеля карток у блоці «Спонсоровані» (не квота — див. config/rating.php). */
    public static function proBlockLimit(): int
    {
        return max(0, (int) config('rating.popularity.pro_block_limit', 3));
    }

    /**
     * Органічний порядок: популярність → відгуки → назва (стабільний tie-break,
     * щоб пагінація не «стрибала» при однакових значеннях).
     */
    public static function applyOrganicOrder(Builder $query): Builder
    {
        // Ярус за показуваністю оцінки: профілі, чий рейтинг видно
        // (≥ RatingDisplay::MIN_REVIEWS відгуків) — першими; з прихованою
        // оцінкою («оцінка формується») — нижче; без відгуків — останні.
        // Раніше популярність (перегляди) виводила профілі з 5–12 відгуками
        // в топ-5 міста над тими, що мають сотні відгуків (19.09.2026).
        $min = (int) RatingDisplay::MIN_REVIEWS;

        return $query
            ->orderByRaw("CASE WHEN profiles.reviews_count >= {$min} THEN 2 WHEN profiles.reviews_count >= 1 THEN 1 ELSE 0 END DESC")
            ->orderByDesc('profiles.popularity_score')
            ->orderByDesc('profiles.reviews_count')
            ->orderBy('profiles.name')
            ->orderBy('profiles.id');
    }

    /**
     * ID платних профілів, які піднімаються у видачу: по ОДНОМУ на бренд.
     * Якщо в базі кілька карток одного бізнесу (а так буває: «Правовий Лідер»
     * має три дублі, усі з підпискою), нагору йде та, де більше відгуків —
     * решта лишається у звичайній видачі. Інакше один бренд займав би підряд
     * кілька верхніх місць і це виглядало б як спам.
     *
     * @param  Builder  $filteredQuery  запит каталогу з усіма фільтрами (не мутується)
     * @return array<int, int>
     */
    public static function promotedIds(Builder $filteredQuery): array
    {
        $rows = (clone $filteredQuery)
            ->where('profiles.is_pro', true)
            ->reorder()
            ->select(['profiles.id', 'profiles.owner_user_id', 'profiles.name', 'profiles.website', 'profiles.reviews_count', 'profiles.popularity_score'])
            ->orderByDesc('profiles.reviews_count')
            ->orderByDesc('profiles.popularity_score')
            ->orderBy('profiles.id')
            ->limit(200)
            ->get();

        $best = [];
        $reviews = [];
        foreach ($rows as $row) {
            $site = preg_replace('#^(https?://)?(www\.)?#i', '', mb_strtolower(trim((string) $row->website)));
            $site = rtrim((string) $site, '/');

            // Ключ — САМЕ БІЗНЕС, а не власник акаунта: однаковий сайт або
            // однакова назва. За власником склеювати не можна — в одного
            // акаунта бувають різні бізнеси (адвокат і його юркомпанія, різні
            // сайти), і кожен з них має право на своє місце.
            $key = $site !== ''
                ? 'site:' . $site
                : 'name:' . preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string) $row->name));

            // Рядки вже відсортовані за кількістю відгуків — перший на ключ і є переможцем.
            if (! isset($best[$key])) {
                $best[$key] = (int) $row->id;
                $reviews[(int) $row->id] = (int) $row->reviews_count;
            }
        }

        $ids = array_values($best);
        if (count($ids) < 2) {
            return $ids;
        }

        // Денна ротація: перше місце серед платних по черзі дістається кожному
        // (сьогодні один, завтра наступний). Зсув детермінований — у межах доби
        // порядок стабільний, тож не ламає кеш і пагінацію.
        $offset = (int) now()->dayOfYear % count($ids);
        $rotated = array_merge(array_slice($ids, $offset), array_slice($ids, 0, $offset));

        // На САМЕ ПЕРШЕ місце не пускаємо профіль без жодного відгуку: порожня
        // картка на вершині каталогу підриває довіру до всього списку. У ротації
        // такий профіль бере участь, просто не стає першим.
        if (($reviews[$rotated[0]] ?? 0) === 0) {
            foreach ($rotated as $index => $id) {
                if (($reviews[$id] ?? 0) > 0) {
                    unset($rotated[$index]);
                    array_unshift($rotated, $id);
                    break;
                }
            }
        }

        return array_values($rotated);
    }

    /**
     * ID профілів для PRO-блоку: з тієї ж (уже відфільтрованої) вибірки,
     * лише активні PRO, за популярністю, з денною ротацією поверх ліміту.
     *
     * @param  Builder  $filteredQuery  запит каталогу з усіма фільтрами (не мутується)
     * @return array<int, int>
     */
    public static function proBlockIds(Builder $filteredQuery, ?int $limit = null, int $poolCap = 30): array
    {
        $limit ??= self::proBlockLimit();
        if ($limit < 1) {
            return [];
        }

        $rows = (clone $filteredQuery)
            ->where('profiles.is_pro', true)
            // reorder() прибирає сортування каталогу, select() — важкі
            // withCount/withExists-колонки: тут потрібні лише ці три поля.
            ->reorder()
            ->select(['profiles.id', 'profiles.owner_user_id', 'profiles.name', 'profiles.website'])
            ->orderByDesc('profiles.popularity_score')
            ->orderBy('profiles.id')
            ->limit(max($limit, $poolCap))
            ->get();

        // Один слот на власника/бренд. У базі трапляються і дублі одного
        // бізнесу, і філії мережі в різних містах (спільний сайт) — без цього
        // один бренд забирав би кілька карток блоку, витісняючи інших
        // передплатників. Пріоритет ключа: власник → сайт → нормалізована назва.
        $seen = [];
        $pool = [];
        foreach ($rows as $row) {
            $site = preg_replace('#^(https?://)?(www\.)?#i', '', mb_strtolower(trim((string) $row->website)));
            $site = rtrim((string) $site, '/');

            $key = match (true) {
                (bool) $row->owner_user_id => 'owner:' . $row->owner_user_id,
                $site !== '' => 'site:' . $site,
                default => 'name:' . preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string) $row->name)),
            };

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $pool[] = (int) $row->id;
        }

        if (count($pool) < 2) {
            return $pool;
        }

        // Денна ротація порядку: детермінована (стабільна в межах доби — не
        // ламає кеш і пагінацію), але за тиждень першу позицію отримує кожен.
        $offset = (int) now()->dayOfYear % count($pool);
        $rotated = array_merge(array_slice($pool, $offset), array_slice($pool, 0, $offset));

        return array_slice($rotated, 0, $limit);
    }

    /**
     * Впорядкувати вже завантажені моделі так, як задано списком id
     * (Eloquent повертає їх у довільному порядку при whereIn).
     *
     * @param  array<int, int>  $ids
     */
    public static function sortByIdList(Collection $profiles, array $ids): Collection
    {
        $position = array_flip($ids);

        return $profiles
            ->sortBy(fn ($profile) => $position[$profile->id] ?? PHP_INT_MAX)
            ->values();
    }
}
