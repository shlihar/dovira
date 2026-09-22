<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Нормалізація тексту для пошукового індексу профілів і для самих запитів.
 * Одні й ті самі правила з обох боків — інакше «Вакцинація» з відгуку і
 * «вакцинація» із запиту не зустрінуться.
 */
final class ProfileSearchTerms
{
    /** Мінімальна довжина слова: коротші не несуть сенсу і засмічують індекс. */
    public const MIN_WORD = 4;

    /**
     * Скільки ключових слів з відгуків тримаємо на профіль. 200, а не 60:
     * при 60 рідкісні, але найцінніші за наміром слова («загиблих», «спадщина»)
     * не потрапляли в індекс — із 36 профілів зі словом «загибл» індексувались
     * лише 4. Ціна питання невелика: індекс важить одиниці десятків мегабайт.
     */
    public const KEYWORDS_PER_PROFILE = 200;

    /**
     * Загальновживані слова відгуків: вони є майже в кожного, тож нічого не
     * розрізняють, лише роздувають індекс.
     *
     * @var array<int, string>
     */
    private const STOPWORDS = [
        'дуже', 'дякую', 'дякуємо', 'рекомендую', 'рекомендуємо', 'раджу', 'дякувати',
        'добре', 'гарно', 'чудово', 'супер', 'класно', 'відмінно', 'приємно', 'задоволена',
        'задоволений', 'задоволені', 'сподобалось', 'сподобалося', 'сподобался',
        'спасибо', 'большое', 'хорошо', 'отлично', 'рекомендую', 'советую', 'довольны',
        'понравилось', 'спасибі', 'вдячна', 'вдячний', 'вдячні',
        'звернувся', 'звернулась', 'звернулися', 'звернення', 'компанія', 'компании',
        'працівники', 'співробітники', 'персонал', 'фахівці', 'спеціаліст', 'спеціалісти',
        'обслуговування', 'ставлення', 'професіонал', 'професіонали', 'професійно',
        'швидко', 'якісно', 'ціна', 'ціни', 'вартість', 'результат', 'питання', 'допомогли',
        'допомога', 'допоміг', 'допомогла', 'завжди', 'також', 'просто', 'взагалі',
        'можна', 'треба', 'після', 'перед', 'через', 'тому', 'тобто', 'якщо', 'коли',
        'дякуючи', 'завдяки', 'нарешті', 'більше', 'менше', 'краще', 'гірше', 'нічого',
        'нормально', 'зробили', 'зробив', 'зробила', 'робота', 'роботу', 'роботи',
    ];

    /** @var array<string, true>|null */
    private static ?array $stopSet = null;

    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(['ʼ', '’', '`', "'"], '', $text);
        // Усе, що не літера й не цифра, стає пробілом: розділові знаки, емодзі, переноси.
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * Значущі слова запиту користувача (для пошуку).
     *
     * @return array<int, string>
     */
    public static function queryWords(string $query): array
    {
        $words = array_filter(
            explode(' ', self::normalize($query)),
            static fn (string $word): bool => mb_strlen($word) >= 3
        );

        return array_values(array_unique($words));
    }

    /**
     * Запит у вигляді boolean-виразу FULLTEXT: усі слова обовʼязкові, кожне —
     * за префіксом. Префікс критичний для української: у відгуку може бути
     * «вакцинацію», у запиті «вакцинація», тому від довгих слів відрізаємо
     * два останні символи («вакцинац*»).
     */
    /**
     * Основи слів запиту з відрізаним закінченням («вакцинація» → «вакцинац»).
     *
     * @return array<int, string>
     */
    public static function stems(string $query): array
    {
        $stems = [];
        foreach (self::queryWords($query) as $word) {
            $stem = mb_strlen($word) >= 7 ? mb_substr($word, 0, mb_strlen($word) - 2) : $word;
            $stem = preg_replace('/[^\p{L}\p{N}]/u', '', $stem) ?? '';
            if (mb_strlen($stem) >= 3) {
                $stems[] = $stem;
            }
        }

        return array_values(array_unique($stems));
    }

    /**
     * @param array<int, string> $stems
     * @param string|null $required основа, яка лишається обовʼязковою (решта — бажані)
     */
    public static function buildBoolean(array $stems, ?string $required = null): ?string
    {
        if ($stems === []) {
            return null;
        }

        $parts = [];
        foreach ($stems as $stem) {
            $mandatory = $required === null || $stem === $required;
            $parts[] = ($mandatory ? '+' : '') . $stem . '*';
        }

        return implode(' ', $parts);
    }

    public static function booleanQuery(string $query, bool $strict = true): ?string
    {
        $stems = self::stems($query);
        if ($stems === [] || $strict || count($stems) === 1) {
            return self::buildBoolean($stems);
        }

        return self::buildBoolean($stems, self::rarestStem($stems));
    }

    /**
     * Найрідкісніша основа: саме вона несе намір. «Заміна паспорта» без цього
     * ставила обовʼязковим слово «заміна» (воно є у відгуках про вікна й
     * кондиціонери) і видавала повний шум.
     *
     * @param array<int, string> $stems
     */
    /**
     * Загальні дієслівні слова: вони не можуть бути «якорем» запиту, бо
     * зустрічаються у відгуках про що завгодно. «Заміна паспорта» через них
     * видавала магазини вікон: слово «заміна» рідкісніше за «паспорт», але
     * наміру не несе.
     *
     * @var array<int, string>
     */
    private const GENERIC_STEMS = [
        'замін', 'замен', 'встановл', 'установ', 'монтаж', 'оформл', 'отрим',
        'послуг', 'консульт', 'купит', 'купів', 'вартіс', 'вибір', 'допомог',
        'супров', 'зробит', 'зроби', 'потріб',
    ];

    private static function isGeneric(string $stem): bool
    {
        foreach (self::GENERIC_STEMS as $generic) {
            if (str_starts_with($stem, $generic) || str_starts_with($generic, $stem)) {
                return true;
            }
        }

        return false;
    }

    private static function rarestStem(array $stems): string
    {
        // Спершу шукаємо якір серед змістовних слів, і лише якщо таких немає —
        // серед усіх.
        $meaningful = array_values(array_filter($stems, static fn (string $stem): bool => ! self::isGeneric($stem)));
        $candidates = $meaningful !== [] ? $meaningful : $stems;

        $best = $candidates[0];
        $bestCount = null;
        foreach ($candidates as $stem) {
            $count = rescue(
                fn (): int => (int) \Illuminate\Support\Facades\DB::table('profile_search_terms')
                    ->whereRaw('MATCH(terms) AGAINST (? IN BOOLEAN MODE)', ['+' . $stem . '*'])
                    ->count(),
                PHP_INT_MAX,
                false
            );
            if ($count > 0 && ($bestCount === null || $count < $bestCount)) {
                $best = $stem;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * Готовий вираз для пошуку: спершу пробуємо строгий (усі слова), і лише
     * якщо він нічого не дає — помʼякшений. Перевірка коштує один швидкий
     * FULLTEXT-запит по 34 тис. рядків.
     */
    public static function resolveBooleanQuery(string $query): ?string
    {
        static $cache = [];
        $key = mb_strtolower(trim($query));
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $strict = self::booleanQuery($query, true);
        if ($strict === null || ! self::indexAvailable()) {
            return $cache[$key] = $strict;
        }

        $hasStrictHits = rescue(
            fn (): bool => \Illuminate\Support\Facades\DB::table('profile_search_terms')
                ->whereRaw('MATCH(terms) AGAINST (? IN BOOLEAN MODE)', [$strict])
                ->exists(),
            true,
            false
        );

        return $cache[$key] = $hasStrictHits ? $strict : self::booleanQuery($query, false);
    }

    /** Індекс може бути ще не створений (свіжий деплой) — тоді пошук працює по-старому. */
    public static function indexAvailable(): bool
    {
        static $available = null;

        return $available ??= rescue(
            fn (): bool => \Illuminate\Support\Facades\Schema::hasTable('profile_search_terms'),
            false,
            false
        );
    }

    /**
     * Ключові слова профілю з тексту його відгуків: найчастіші значущі слова.
     *
     * @return array<int, string>
     */
    public static function keywords(string $reviewsText, int $limit = self::KEYWORDS_PER_PROFILE): array
    {
        self::$stopSet ??= array_fill_keys(self::STOPWORDS, true);

        $counts = [];
        foreach (explode(' ', self::normalize($reviewsText)) as $word) {
            if (mb_strlen($word) < self::MIN_WORD || isset(self::$stopSet[$word]) || ctype_digit($word)) {
                continue;
            }
            $counts[$word] = ($counts[$word] ?? 0) + 1;
        }

        arsort($counts);

        return array_slice(array_keys($counts), 0, $limit);
    }
}
