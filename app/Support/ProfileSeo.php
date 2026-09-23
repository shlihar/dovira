<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Plural;
use Illuminate\Support\Str;

final class ProfileSeo
{
    private const TITLE_MAX_LENGTH = 68;
    private const DESCRIPTION_MAX_LENGTH = 160;

    /**
     * @param  array<int, string>  $services
     */
    public static function defaultTitle(
        ?string $name,
        ?string $categoryName = null,
        ?string $city = null,
        bool $ratingVisible = false,
        float $rating = 0.0,
        int $reviewsCount = 0,
        bool $hasDossier = false,
        bool $hasAiSummary = false
    ): string {
        $name = self::normalizeText($name) ?: 'Профіль DOVIRA';
        $keyword = self::categoryKeyword($categoryName);
        $city = self::normalizeText($city);
        $nameHasKeyword = $keyword !== null && self::nameContainsKeyword($name, $keyword);
        $hasRating = $ratingVisible && $rating > 0 && $reviewsCount > 0;
        $ratingValue = $hasRating ? self::formatRatingValue($rating) : null;
        $ratingOnly = $hasRating ? "рейтинг {$ratingValue}/5" : null;
        // Кількість відгуків як соц-доказ у заголовку («4.9/5 · 1000 відгуків»).
        // Число видно навіть без зіркового rich-snippet і воно тягне CTR.
        $ratingWithCount = $hasRating
            ? "{$ratingValue}/5 · " . $reviewsCount . ' '
                . Plural::uk($reviewsCount, 'відгук', 'відгуки', 'відгуків')
            : null;
        $tail = $hasDossier ? 'відгуки, рейтинг і досьє' : 'відгуки і рейтинг';

        // CTR-кандидати попереду: досьє-профілі отримують «досьє» + інтригу
        // «що варто знати» в тайтлі, AI-профілі — маркер «AI-розбір».
        // Формулювання нейтральні (без вердикту): тайтл — найпомітніше місце
        // видачі, і Google переписує/карає перегрітий текст саме там.
        $dossierCandidates = $hasDossier ? array_values(array_filter([
            $hasRating ? "{$name}: відгуки {$ratingValue}/5 і досьє — що варто знати | DOVIRA" : null,
            $hasRating ? "{$name} — {$ratingWithCount}, досьє | DOVIRA" : null,
            "{$name}: досьє і відгуки — що варто знати | DOVIRA",
            "{$name} — досьє і відгуки | DOVIRA",
        ])) : [];

        $aiCandidates = ! $hasDossier && $hasAiSummary ? array_values(array_filter([
            $hasRating ? "{$name} — відгуки {$ratingValue}/5: AI-розбір | DOVIRA" : null,
            "{$name} відгуки: AI-розбір плюсів і мінусів | DOVIRA",
        ])) : [];

        // Профіль без видимого рейтингу: у заголовку немає цифр, тож місце
        // віддаємо ключам, за якими людина шукає — категорія + місто.
        // Раніше перемагав варіант «{name} відгуки і рейтинг», який на
        // коротких назвах з'їдав лише 37 із 68 символів і не ловив жодного
        // географічного чи категорійного запиту.
        $keywordCityCandidates = ! $hasRating ? array_values(array_filter([
            $keyword !== null && ! $nameHasKeyword && $city !== null
                ? "{$name}, {$keyword} — {$city}: відгуки | DOVIRA" : null,
            $keyword !== null && ! $nameHasKeyword
                ? "{$name}, {$keyword} — відгуки і рейтинг | DOVIRA" : null,
            $city !== null ? "{$name} — {$city}: відгуки і рейтинг | DOVIRA" : null,
        ])) : [];

        $candidates = array_merge($dossierCandidates, $aiCandidates, $keywordCityCandidates, array_values(array_filter([
            $ratingWithCount !== null ? "{$name} — {$ratingWithCount} | DOVIRA" : null,
            $ratingOnly !== null ? "{$name} відгуки: {$ratingOnly} | DOVIRA" : null,
            "{$name} {$tail} | DOVIRA",
            $city !== null ? "{$name} відгуки — {$city} | DOVIRA" : null,
            $keyword !== null && ! $nameHasKeyword ? "{$name} відгуки — {$keyword} | DOVIRA" : null,
            "{$name} відгуки | DOVIRA",
        ])));

        foreach ($candidates as $candidate) {
            if (mb_strlen($candidate) <= self::TITLE_MAX_LENGTH) {
                return $candidate;
            }
        }

        // Дуже довга назва: ріжемо її, але ключі «досьє»/«відгуки» лишаємо —
        // голий «{name} | DOVIRA» без ключових слів у видачу не віддаємо.
        $compactTail = $hasDossier ? ' — досьє, відгуки | DOVIRA' : ' відгуки | DOVIRA';
        $compactNameLimit = max(18, self::TITLE_MAX_LENGTH - mb_strlen($compactTail) - mb_strlen('...'));

        return Str::limit($name, $compactNameLimit, '...') . $compactTail;
    }

    /**
     * @param  array<int, string>  $services
     */
    public static function defaultDescription(
        ?string $name,
        ?string $categoryName = null,
        ?string $city = null,
        array $services = [],
        ?string $shortDescription = null,
        ?string $description = null,
        bool $ratingVisible = false,
        float $rating = 0.0,
        int $reviewsCount = 0,
        bool $hasDossier = false,
        ?string $dossierVerdict = null,
        ?string $dossierVerdictNote = null,
        ?array $aiReviewSummary = null
    ): string {
        $name = self::normalizeText($name) ?: 'Профіль DOVIRA';
        $keyword = self::categoryKeyword($categoryName);
        $city = self::normalizeText($city);
        $aboutSentence = self::buildAboutSentence($shortDescription, $description);
        $reviewLeadSentence = self::buildReviewLeadSentence($name, $keyword, $city, $ratingVisible, $rating, $reviewsCount);
        $reputationSentence = ($ratingVisible || $hasDossier) ? self::buildReputationSentence($hasDossier) : null;
        $profileSentence = self::buildProfileSentence($name, $services, $aboutSentence);
        $supportingSentence = self::profileSentenceHasCallToAction($profileSentence)
            ? null
            : self::supportingSeoSentence($keyword);

        // Профілі з досьє: агресивний CTR-сніпет з вердиктом (🔴/⚠️/✅).
        // Формулювання — власна опінія Dovira («тривожні сигнали», «є що
        // перевірити»), а НЕ факт-звинувачення; сирий текст досьє і
        // dossier_verdict_note у сніпет не тягнемо ніколи — там бувають
        // імена людей і прямі звинувачення (наклеп у видачі Google).
        if ($hasDossier) {
            $segments = array_values(array_filter([
                self::buildDossierHookSentence($name, $dossierVerdict),
                self::buildCompactProofSentence($ratingVisible, $rating, $reviewsCount),
                self::buildDossierTailCta($dossierVerdict),
            ]));
        } elseif (($aiSegments = self::buildAiSummarySegments($name, $aiReviewSummary, $ratingVisible, $rating, $reviewsCount)) !== null) {
            // Без досьє, але з AI-аналізом відгуків: конкретика «що хвалять /
            // на що скаржаться» з аспектів — теми знеособлені й зіперті на
            // реальні відгуки, тож у сніпеті безпечні.
            $segments = $aiSegments;
        } else {
            $segments = array_values(array_filter([
                $reviewLeadSentence,
                $reputationSentence,
                $profileSentence,
                $supportingSentence,
            ]));
        }

        $result = self::joinSegmentsWithinLimit($segments, self::DESCRIPTION_MAX_LENGTH);

        // Куций сніпет із самого шаблонного ліда — головна причина, чому Google
        // переписує description випадковим текстом сторінки (відгуком, UI-рядками).
        // Якщо реальний опис профілю не вліз цілком (92 зн.) — вкладаємо його
        // обрізаним під фактичний залишок бюджету: справжній текст завжди
        // кращий за порожні 80 символів.
        if (! $hasDossier && $aboutSentence !== null && ! Str::contains($result, mb_substr($aboutSentence, 0, 24))) {
            $budget = self::DESCRIPTION_MAX_LENGTH - mb_strlen($result) - 1;
            if ($budget >= 48) {
                $fittedAbout = self::buildAboutSentence($shortDescription, $description, $budget - 4);
                if ($fittedAbout !== null && mb_strlen($result . ' ' . $fittedAbout) <= self::DESCRIPTION_MAX_LENGTH) {
                    $result = trim($result . ' ' . $fittedAbout);
                }
            }
        }

        return $result !== ''
            ? $result
            : Str::limit($name . ' на DOVIRA.', self::DESCRIPTION_MAX_LENGTH, '...');
    }

    /**
     * @param  array<int, string>  $services
     */
    public static function resolvedTitle(
        ?string $storedValue,
        ?string $name,
        ?string $categoryName = null,
        ?string $city = null
    ): string {
        $generated = self::defaultTitle($name, $categoryName, $city);
        $storedValue = self::normalizeText($storedValue);

        if ($storedValue === null || self::isAutoGeneratedTitle($storedValue, $name, $categoryName, $city)) {
            return $generated;
        }

        return $storedValue;
    }

    /**
     * @param  array<int, string>  $services
     */
    public static function resolvedDescription(
        ?string $storedValue,
        ?string $name,
        ?string $categoryName = null,
        ?string $city = null,
        array $services = [],
        ?string $shortDescription = null,
        ?string $description = null
    ): string {
        $generated = self::defaultDescription($name, $categoryName, $city, $services, $shortDescription, $description);
        $storedValue = self::normalizeText($storedValue);

        if ($storedValue === null || self::isAutoGeneratedDescription($storedValue, $name, $categoryName, $city, $services, $shortDescription, $description)) {
            return $generated;
        }

        return $storedValue;
    }

    public static function resolvedSearchTitle(
        ?string $storedValue,
        ?string $name,
        ?string $categoryName = null,
        ?string $city = null,
        bool $ratingVisible = false,
        float $rating = 0.0,
        int $reviewsCount = 0,
        bool $hasDossier = false,
        bool $hasAiSummary = false
    ): string {
        $generated = self::defaultTitle($name, $categoryName, $city, $ratingVisible, $rating, $reviewsCount, $hasDossier, $hasAiSummary);
        $storedValue = self::normalizeText($storedValue);

        if ($storedValue === null || self::isAutoGeneratedTitle($storedValue, $name, $categoryName, $city)) {
            return $generated;
        }

        // Заголовок уже в канонічній пошуковій формі («{Назва} відгуки…») —
        // віддаємо як є.
        if (self::hasPreferredSearchTitleSignals($storedValue, $name)) {
            return $storedValue;
        }

        // Крафтовий заголовок із пошуковим ключем («відгуки», «досьє»,
        // «рейтинг») віддаємо як є: ключ у ньому вже стоїть, а разом із ним —
        // категорія і місто в живому формулюванні («Адвокат Андрій Смирнов в
        // Одесі — відгуки, досвід»), чого шаблон не дає. Раніше такий заголовок
        // ішов далі й мовчки гинув у craftedSearchTitle, якщо в ньому не було
        // двокрапки, — у видачу йшов шаблон без міста й категорії.
        if (mb_strlen($storedValue) <= self::TITLE_MAX_LENGTH
            && self::titleMentionsProfile($storedValue, $name)
            && self::hasSearchKeySignals($storedValue)) {
            return self::withBrandSuffix($storedValue);
        }

        // Шаблон для довгої назви ріже її трьома крапками посеред слова
        // («Адвокатське об’єднання «Центр юридичної... — досьє, відгуки»).
        // Написаний руками заголовок читабельніший і несе місто з категорією,
        // тож у цьому випадку він виграє — ключ доклеюємо, поки влазить.
        if (str_contains($generated, '...')
            && mb_strlen($storedValue) <= self::TITLE_MAX_LENGTH
            && self::titleMentionsProfile($storedValue, $name)) {
            return self::withSearchKeyAndBrand($storedValue, $hasDossier);
        }

        // Крафтовий хук із досьє: «{Назва}, {Місто}: {суть претензії}». Такий
        // заголовок не починається з ключа «відгуки», і раніше його мовчки
        // відкидало на користь шаблону — разом із єдиним, чого в шаблоні немає:
        // причиною клікнути. Тепер не відкидаємо, а добудовуємо ключ і бренд у
        // межах TITLE_MAX_LENGTH; якщо не влазить навіть куцо — шаблон.
        return self::craftedSearchTitle($storedValue, $name, $hasDossier) ?? $generated;
    }

    /**
     * Приводить крафтовий заголовок до придатного для видачі вигляду: додає
     * пошуковий ключ і бренд, не перевищуючи TITLE_MAX_LENGTH. Повертає null,
     * якщо заголовок не про цей профіль — тоді викликач бере згенерований.
     */
    private static function craftedSearchTitle(string $value, ?string $name, bool $hasDossier): ?string
    {
        if (! self::titleMentionsProfile($value, $name)) {
            return null;
        }

        $value = self::stripBrandSuffix($value);

        // Ділимо на «голову» (назва плюс місто) і хук, щоб ключ став між ними,
        // а не всередині речення.
        $head = $value;
        $hook = null;
        $position = mb_strpos($value, ':');

        if ($position !== false) {
            $head = trim(mb_substr($value, 0, $position));
            $hook = trim(mb_substr($value, $position + 1));
        }

        // Без хука збережений заголовок не додає нічого, чого не дає генератор
        // (а найчастіше це легасі-тайтл «{Назва}, {категорія} - Dovira»), тож
        // перемагати шаблон він не має права.
        if ($head === '' || $hook === null || $hook === '') {
            return null;
        }

        $key = $hasDossier ? 'відгуки і досьє' : 'відгуки';
        $brand = ' | DOVIRA';

        // Порядок кандидатів = порядок поступок: спершу жертвуємо довгим
        // ключем, потім ключем узагалі, потім брендом. Хук лишається завжди —
        // заради нього все й робиться.
        // Голова крафтового заголовка — «{Назва}, {Місто}». Місто дублюється
        // в описі, у H1 і в адресі на сторінці, тож коли не вистачає місця,
        // жертвуємо ним, а не хуком.
        $shortHead = preg_replace('/\s*\([^()]*\)\s*$/u', '', $head);
        $shortHead = trim((string) ($shortHead ?? $head)) ?: $head;

        if ($shortHead === $head) {
            $lastComma = mb_strrpos($head, ', ');

            if ($lastComma !== false && $lastComma > 0) {
                $shortHead = trim(mb_substr($head, 0, $lastComma));
            }
        }

        $candidates = [
            $head . ' — ' . $key . ': ' . $hook . $brand,
            $head . ' — відгуки: ' . $hook . $brand,
            $head . ': ' . $hook . $brand,
            $head . ': ' . $hook,
            $shortHead . ' — відгуки: ' . $hook . $brand,
            $shortHead . ': ' . $hook . $brand,
            $shortHead . ': ' . $hook,
        ];

        foreach ($candidates as $candidate) {
            if (mb_strlen($candidate) <= self::TITLE_MAX_LENGTH) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Чи про цей самий заклад крафтовий заголовок. Точного входження назви
     * вимагати не можна: у заголовках інший порядок слів («Адвокат Малик
     * Олександр» проти «Адвокат Олександр Малик»), лапки («Макс Гарант» проти
     * ««Макс Гарант»») і відкинутий категорійний хвіст («Прораб, будівельні
     * матеріали» проти «Прораб, Полтава»). Тому звіряємо значущі слова.
     */
    private static function titleMentionsProfile(string $value, ?string $name): bool
    {
        $titleWords = self::significantWords($value);
        $nameWords = self::significantWords((string) $name);

        if ($nameWords === [] || $titleWords === []) {
            return false;
        }

        return array_intersect($nameWords, $titleWords) !== [];
    }

    /**
     * @return array<int, string>
     */
    private static function significantWords(string $value): array
    {
        // Родові слова («адвокат», «клініка», «магазин») є майже в кожній
        // назві й збігаються між різними закладами, тож для звірки марні.
        static $generic = [
            'адвокат', 'адвокатське', 'адвокатський', 'адвокатська', 'юрист', 'юридична',
            'юридичний', 'юридичні', 'компанія', 'клініка', 'центр', 'стоматологія',
            'стоматологічна', 'магазин', 'бюро', 'обєднання', "об'єднання", 'послуги',
            'фірма', 'група', 'мережа', 'салон', 'студія', 'сервіс', 'відгуки', 'досьє',
        ];

        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $parts,
            static fn (string $word): bool => mb_strlen($word) >= 4 && ! in_array($word, $generic, true)
        )));
    }

    /**
     * Доклеює пошуковий ключ і бренд до крафтового заголовка в межах ліміту.
     */
    private static function withSearchKeyAndBrand(string $value, bool $hasDossier): string
    {
        if (self::hasSearchKeySignals($value)) {
            return self::withBrandSuffix($value);
        }

        $base = self::stripBrandSuffix($value);
        // У заголовку вже є тире («… — адвокати в Одесі») — другим тире ключ не
        // чіпляємо, бо виходить «— … — відгуки». Ставимо двокрапку.
        $separator = preg_match('/[—–-]/u', $base) === 1 ? ': ' : ' — ';

        foreach ([$hasDossier ? $separator . 'відгуки і досьє' : $separator . 'відгуки', $separator . 'відгуки'] as $key) {
            $candidate = self::withBrandSuffix($base . $key);

            if (mb_strlen($candidate) <= self::TITLE_MAX_LENGTH) {
                return $candidate;
            }
        }

        return self::withBrandSuffix($base);
    }

    /** Чи несе заголовок пошуковий ключ, заради якого сторінка й ранжується.
     * Легасі-тайтли виду «{Назва}, {категорія} - Dovira» ключа не мають —
     * вони програють шаблону, і це правильно.
     */
    private static function hasSearchKeySignals(string $value): bool
    {
        $normalized = mb_strtolower($value);

        foreach (['відгу', 'досьє', 'рейтинг'] as $key) {
            if (str_contains($normalized, $key)) {
                return true;
            }
        }

        return false;
    }

    /** Додає бренд у хвіст, якщо його немає і він влазить у ліміт. */
    private static function withBrandSuffix(string $value): string
    {
        $brand = ' | DOVIRA';

        if (self::stripBrandSuffix($value) !== $value) {
            return $value;
        }

        $withBrand = $value . $brand;

        return mb_strlen($withBrand) <= self::TITLE_MAX_LENGTH ? $withBrand : $value;
    }

    private static function stripBrandSuffix(string $value): string
    {
        $trimmed = preg_replace('/\s*[|\-–—]\s*DOVIRA\s*$/iu', '', $value);

        return trim((string) ($trimmed ?? $value)) ?: $value;
    }

    /**
     * @param  array<int, string>  $services
     */
    public static function resolvedSearchDescription(
        ?string $storedValue,
        ?string $name,
        ?string $categoryName = null,
        ?string $city = null,
        array $services = [],
        ?string $shortDescription = null,
        ?string $description = null,
        bool $ratingVisible = false,
        float $rating = 0.0,
        int $reviewsCount = 0,
        bool $hasDossier = false,
        ?string $dossierVerdict = null,
        ?string $dossierVerdictNote = null,
        ?array $aiReviewSummary = null
    ): string {
        $generated = self::defaultDescription($name, $categoryName, $city, $services, $shortDescription, $description, $ratingVisible, $rating, $reviewsCount, $hasDossier, $dossierVerdict, $dossierVerdictNote, $aiReviewSummary);
        $storedValue = self::normalizeText($storedValue);

        if ($storedValue === null
            || self::isAutoGeneratedDescription($storedValue, $name, $categoryName, $city, $services, $shortDescription, $description)
            || ($hasDossier && ! self::hasDossierSearchSignals($storedValue))
            || ! self::hasProfileSearchSignals($storedValue)) {
            return $generated;
        }

        // Власний (AI/ручний) опис буває змістовним, але без соцдоказу —
        // а рейтинг і кількість відгуків у сніпеті тягнуть CTR сильніше за
        // будь-який текст. Додаємо їх префіксом, якщо в описі цифр ще немає
        // і залишку довжини вистачає.
        return self::withRatingPrefix($storedValue, $ratingVisible, $rating, $reviewsCount);
    }

    /**
     * «⭐ 4.5/5 · 94 відгуки. {опис}» — лише коли рейтинг видимий, у тексті
     * ще немає оцінки і сумарна довжина влазить у ліміт сніпета.
     */
    public static function withRatingPrefix(
        string $description,
        bool $ratingVisible,
        float $rating,
        int $reviewsCount
    ): string {
        if (! $ratingVisible || $rating <= 0 || $reviewsCount <= 0) {
            return $description;
        }

        // В описі вже є оцінка («4.5/5», «рейтинг 4,5») — другий раз не треба.
        if (preg_match('/\d[.,]\d\s*\/\s*5|рейтинг/iu', $description) === 1) {
            return $description;
        }

        $prefix = '⭐ ' . self::formatRatingValue($rating) . '/5 · '
            . $reviewsCount . ' ' . self::reviewsWord($reviewsCount) . '. ';

        if (mb_strlen($prefix . $description) <= self::DESCRIPTION_MAX_LENGTH) {
            return $prefix . $description;
        }

        // Опис задовгий для префікса. Раніше в такому разі рейтинг просто
        // губився — а це найсильніший елемент сніпета і водночас той ключ
        // («відгуки»), за яким сторінку й шукають. Тому не відкидаємо префікс,
        // а підрізаємо хвіст опису по межі речення.
        $room = self::DESCRIPTION_MAX_LENGTH - mb_strlen($prefix);
        $trimmed = self::trimToSentence($description, $room);

        return $trimmed !== null ? $prefix . $trimmed : $description;
    }

    /**
     * Обрізає текст до $limit по межі речення, інакше по межі слова.
     * Повертає null, якщо осмисленого залишку не лишається.
     */
    private static function trimToSentence(string $value, int $limit): ?string
    {
        $value = trim($value);

        if ($limit < 40) {
            return null;
        }

        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        $head = mb_substr($value, 0, $limit);

        foreach (['. ', '! ', '? '] as $stop) {
            $position = mb_strrpos($head, $stop);

            if ($position !== false && $position >= 40) {
                return trim(mb_substr($head, 0, $position + 1));
            }
        }

        $space = mb_strrpos($head, ' ');

        if ($space === false || $space < 40) {
            return null;
        }

        return rtrim(trim(mb_substr($head, 0, $space)), ',;:—–-') . '.';
    }

    /**
     * Дописує ключ «відгуки» в хвіст опису, якщо його там немає, а відгуки в
     * профілі реально є. Без цього опис на платформі відгуків не збігається з
     * запитом «{назва} відгуки», і Google переписує сніпет своїм текстом.
     */
    public static function withReviewKeyword(string $description, int $reviewsCount): string
    {
        if ($reviewsCount <= 0 || preg_match('/відгук|⭐/iu', $description) === 1) {
            return $description;
        }

        $tail = ' ' . $reviewsCount . ' ' . self::reviewsWord($reviewsCount) . ' клієнтів на DOVIRA.';
        $base = rtrim(trim($description));

        if ($base === '') {
            return $description;
        }

        if (! str_ends_with($base, '.') && ! str_ends_with($base, '!') && ! str_ends_with($base, '?')) {
            $base .= '.';
        }

        return mb_strlen($base . $tail) <= self::DESCRIPTION_MAX_LENGTH
            ? $base . $tail
            : $description;
    }

    /**
     * Видимий на сторінці самодостатній абзац-відповідь (40–60 слів): те, що
     * AI-пошук цитує дослівно на запит «[назва] відгуки». Будується детерміновано
     * зі структурних полів — без AI-токенів, покриває будь-який профіль.
     *
     * @param  array<int, string>  $services
     */
    public static function answerParagraph(
        ?string $name,
        ?string $categoryName = null,
        ?string $city = null,
        array $services = [],
        bool $ratingVisible = false,
        float $rating = 0.0,
        int $reviewsCount = 0
    ): string {
        $name = self::normalizeText($name) ?: 'Профіль DOVIRA';
        $keyword = self::categoryKeyword($categoryName);
        $cityPhrase = self::cityLocativePhrase($city);
        $nameHasKeyword = $keyword !== null && self::nameContainsKeyword($name, $keyword);

        // Речення 1: хто це і де.
        $descriptor = $keyword !== null && ! $nameHasKeyword ? " — {$keyword}" : '';
        $where = $cityPhrase !== null ? " {$cityPhrase}" : '';
        $sentence1 = self::ensureSentence(trim("{$name}{$descriptor}{$where}"));

        // Речення 2: оцінка / відгуки (числа, які цінує AI-цитування).
        if ($ratingVisible && $rating > 0 && $reviewsCount > 0) {
            $sentence2 = 'Рейтинг ' . self::formatRatingValue($rating) . ' з 5 — '
                . $reviewsCount . ' ' . self::reviewsWord($reviewsCount) . ' на DOVIRA.';
        } elseif ($reviewsCount > 0) {
            $sentence2 = 'Оцінка ще формується — ' . $reviewsCount . ' '
                . self::reviewsWord($reviewsCount) . ' на DOVIRA.';
        } else {
            $sentence2 = 'Профіль на DOVIRA — залиште перший відгук про свій досвід.';
        }

        // Речення 3: основні напрями (послуги), якщо є.
        $serviceNames = self::serviceNames($services);
        $sentence3 = $serviceNames->isNotEmpty()
            ? 'Основні напрями: ' . $serviceNames->implode(', ') . '.'
            : null;

        return trim(implode(' ', array_filter([$sentence1, $sentence2, $sentence3])));
    }

    /**
     * Пluralізація «відгук / відгуки / відгуків» — та сама, що на сторінці профілю.
     */
    public static function reviewsWord(int $count): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return 'відгук';
        }

        if (in_array($mod10, [2, 3, 4], true) && ! in_array($mod100, [12, 13, 14], true)) {
            return 'відгуки';
        }

        return 'відгуків';
    }

    /**
     * «4.8» без хвостового нуля («5.0» → «5»), щоб у прозі читалося природно.
     */
    private static function formatRatingValue(float $rating): string
    {
        $formatted = number_format($rating, 1, '.', '');

        return str_ends_with($formatted, '.0') ? substr($formatted, 0, -2) : $formatted;
    }

    public static function isAutoGeneratedTitle(
        ?string $value,
        ?string $name,
        ?string $categoryName = null,
        ?string $city = null
    ): bool {
        $value = self::normalizeComparisonValue($value);

        if ($value === null) {
            return true;
        }

        return in_array($value, collect(self::legacyTitleVariants($name, $categoryName, $city))
            ->merge(self::previousAutoGeneratedTitleVariants($name, $categoryName, $city))
            ->push(self::defaultTitle($name, $categoryName, $city))
            ->map(fn ($item) => self::normalizeComparisonValue($item))
            ->filter()
            ->values()
            ->all(), true);
    }

    /**
     * @param  array<int, string>  $services
     */
    public static function isAutoGeneratedDescription(
        ?string $value,
        ?string $name,
        ?string $categoryName = null,
        ?string $city = null,
        array $services = [],
        ?string $shortDescription = null,
        ?string $description = null
    ): bool {
        $value = self::normalizeComparisonValue($value);

        if ($value === null) {
            return true;
        }

        return in_array($value, collect(self::legacyDescriptionVariants($name, $categoryName, $city))
            ->merge(self::previousAutoGeneratedDescriptionVariants($name, $categoryName, $city, $services, $shortDescription, $description))
            ->push(self::defaultDescription($name, $categoryName, $city, $services, $shortDescription, $description))
            ->map(fn ($item) => self::normalizeComparisonValue($item))
            ->filter()
            ->values()
            ->all(), true);
    }

    /**
     * @return array<int, string>
     */
    private static function legacyTitleVariants(?string $name, ?string $categoryName = null, ?string $city = null): array
    {
        $name = self::normalizeText($name) ?: 'Профіль';
        $city = self::normalizeText($city);

        return array_values(array_filter([
            "{$name}" . ($city ? " {$city}" : '') . ' — відгуки, контакти, інформація | DOVIRA',
            $categoryName ? "{$name} — " . mb_strtolower((string) $categoryName) . ($city ? " в {$city}" : '') . ' | DOVIRA' : null,
            "{$name} — DOVIRA",
        ]));
    }

    /**
     * @return array<int, string>
     */
    private static function previousAutoGeneratedTitleVariants(?string $name, ?string $categoryName = null, ?string $city = null): array
    {
        $name = self::normalizeText($name) ?: 'Профіль DOVIRA';
        $keyword = self::categoryKeyword($categoryName);
        $city = self::normalizeText($city);
        $nameHasKeyword = $keyword !== null && self::nameContainsKeyword($name, $keyword);

        return array_values(array_filter([
            $keyword !== null && $city !== null && ! $nameHasKeyword ? "{$name} — {$keyword}, {$city} | DOVIRA" : null,
            $city !== null ? "{$name} — {$city}: відгуки, контакти | DOVIRA" : null,
            $keyword !== null && ! $nameHasKeyword ? "{$name} — {$keyword}: відгуки, контакти | DOVIRA" : null,
            "{$name} — відгуки, контакти | DOVIRA",
            "{$name} | DOVIRA",
        ]));
    }

    /**
     * @return array<int, string>
     */
    private static function legacyDescriptionVariants(?string $name, ?string $categoryName = null, ?string $city = null): array
    {
        $name = self::normalizeText($name) ?: 'Профіль';
        $categoryName = self::normalizeCategoryName($categoryName);
        $city = self::normalizeText($city);

        return array_values(array_filter([
            'Інформація про ' . $name . ($city ? ' у місті ' . $city : '') . ': контакти, джерела, відгуки та репутація на DOVIRA.',
            trim(collect([
                $name,
                $categoryName ? '— ' . mb_strtolower($categoryName) : null,
                $city ? 'у ' . $city : null,
            ])->filter()->implode(' ') . '. Контакти, адреса, відгуки та детальна інформація на DOVIRA.'),
            'Профіль на DOVIRA',
        ]));
    }

    /**
     * @return array<int, string>
     */
    private static function previousAutoGeneratedDescriptionVariants(
        ?string $name,
        ?string $categoryName = null,
        ?string $city = null,
        array $services = [],
        ?string $shortDescription = null,
        ?string $description = null
    ): array {
        $name = self::normalizeText($name) ?: 'Профіль';
        $keyword = self::categoryKeyword($categoryName);
        $city = self::normalizeText($city);
        $nameHasKeyword = $keyword !== null && self::nameContainsKeyword($name, $keyword);
        $rawAbout = self::rawAboutText($shortDescription, $description);
        $oldSearchDescription = self::previousSearchGeneratedDescription($name, $keyword, $city, $services, $shortDescription, $description);

        return array_values(array_filter([
            $keyword !== null && $city !== null && ! $nameHasKeyword ? "{$name} — {$keyword}, {$city}." : null,
            $city !== null ? "{$name} — {$city}." : null,
            $keyword !== null && ! $nameHasKeyword ? "{$name} — {$keyword}." : null,
            $keyword !== null && $city !== null && ! $nameHasKeyword && $rawAbout !== null ? "{$name} — {$keyword}, {$city}. {$rawAbout}" : null,
            $city !== null && $rawAbout !== null ? "{$name} — {$city}. {$rawAbout}" : null,
            $keyword !== null && ! $nameHasKeyword && $rawAbout !== null ? "{$name} — {$keyword}. {$rawAbout}" : null,
            $oldSearchDescription,
        ]));
    }

    private static function previousSearchGeneratedDescription(
        string $name,
        ?string $keyword,
        ?string $city,
        array $services,
        ?string $shortDescription,
        ?string $description
    ): ?string {
        $searchSentence = self::buildSearchSentence($keyword, $city);
        $profileSentence = self::buildPreviousSearchProfileSentence($name, $services, self::buildAboutSentence($shortDescription, $description));

        return self::joinSegmentsWithinLimit(array_values(array_filter([
            $searchSentence,
            $profileSentence,
            self::previousSearchSupportingSeoSentence($keyword),
        ])), self::DESCRIPTION_MAX_LENGTH) ?: null;
    }

    private static function buildAboutSentence(?string $shortDescription, ?string $description, int $maxLength = 92): ?string
    {
        $source = self::rawAboutText($shortDescription, $description);

        if ($source === null) {
            return null;
        }

        if (mb_strlen($source) < 32 || self::isLikelyPlaceholderText($source)) {
            return null;
        }

        $source = preg_replace('/\s+/u', ' ', $source);
        $source = preg_replace('/\s*([,.!?;:])\s*/u', '$1 ', $source);
        $source = trim((string) $source);

        // Str::limit різав посеред слова («…широкий спектр п...»), і такий
        // обрив ішов прямо у сніпет Google. Ріжемо по межі речення або слова.
        return self::ensureSentence(self::trimToSentence($source, $maxLength) ?? Str::limit($source, $maxLength, '...'));
    }

    private static function rawAboutText(?string $shortDescription, ?string $description): ?string
    {
        return self::normalizeText($shortDescription)
            ?: self::normalizeText($description);
    }

    private static function buildSearchSentence(?string $keyword, ?string $city): ?string
    {
        $cityPhrase = self::cityLocativePhrase($city);

        return match (mb_strtolower((string) $keyword)) {
            'адвокат' => $cityPhrase !== null ? "Шукаєте адвоката {$cityPhrase}?" : 'Шукаєте адвоката?',
            'юрист' => $cityPhrase !== null ? "Потрібен юрист {$cityPhrase}?" : 'Потрібен юрист?',
            'стоматолог' => $cityPhrase !== null ? "Шукаєте стоматолога {$cityPhrase}?" : 'Шукаєте стоматолога?',
            'нотаріус' => $cityPhrase !== null ? "Потрібен нотаріус {$cityPhrase}?" : 'Потрібен нотаріус?',
            'спеціаліст' => $cityPhrase !== null ? "Шукаєте спеціаліста {$cityPhrase}?" : 'Шукаєте спеціаліста?',
            'компанія', 'магазин', 'сервіс' => $cityPhrase !== null ? "Шукаєте компанію {$cityPhrase}?" : 'Шукаєте компанію?',
            default => $cityPhrase !== null ? "Перегляньте профіль {$cityPhrase}." : null,
        };
    }

    private static function buildReviewLeadSentence(
        string $name,
        ?string $keyword,
        ?string $city,
        bool $ratingVisible = false,
        float $rating = 0.0,
        int $reviewsCount = 0
    ): string {
        if ($ratingVisible && $rating > 0 && $reviewsCount > 0) {
            return 'Рейтинг ' . self::formatRatingValue($rating) . '/5: '
                . $reviewsCount . ' ' . self::reviewsWord($reviewsCount)
                . " про {$name} на DOVIRA.";
        }

        $cityPhrase = self::cityLocativePhrase($city);
        $nameHasKeyword = $keyword !== null && self::nameContainsKeyword($name, $keyword);
        $context = $keyword !== null && ! $nameHasKeyword
            ? ', ' . self::keywordGenitive($keyword)
            : '';

        if ($cityPhrase !== null) {
            return "Відгуки про {$name}{$context} {$cityPhrase}.";
        }

        return "Відгуки про {$name}{$context} на DOVIRA.";
    }

    private static function buildReputationSentence(bool $hasDossier): string
    {
        // Друге речення сніпета: після цифр рейтингу воно має казати, ЩО
        // людина знайде на сторінці, а не повторювати абстракції. Стара
        // версія («Оцінка роботи, репутація та досвід клієнтів») була
        // однакова на тисячах сторінок і не давала жодної причини клікнути.
        return $hasDossier
            ? 'Досьє з відкритих джерел, контакти й відповіді компанії.'
            : 'Реальні коментарі клієнтів, контакти та рейтинг за категорією.';
    }

    /**
     * Хук-речення сніпета для профілю з досьє: вердикт-емодзі + жорстке
     * формулювання-опінія Dovira. Це НЕ факт-звинувачення («шахраї»,
     * «викачують гроші» — заборонено), а оцінка платформи з закликом
     * перевірити самому; сирий текст досьє сюди не потрапляє.
     */
    private static function buildDossierHookSentence(string $name, ?string $dossierVerdict): string
    {
        return match ($dossierVerdict) {
            'red' => "🔴 {$name}: у досьє — серйозні тривожні сигнали. Прочитайте, перш ніж звертатися.",
            'yellow' => "⚠️ {$name}: є що перевірити. Суперечливі сигнали в досьє.",
            'green' => "✅ {$name}: перевірено — компромату не знайдено. Досьє з відкритих джерел.",
            default => "Що варто знати про {$name}? Досьє: факти, джерела, репутація.",
        };
    }

    private static function buildDossierTailCta(?string $dossierVerdict): string
    {
        return match ($dossierVerdict) {
            // red-хук уже закінчується закликом «прочитайте» — хвіст коротший.
            'red' => 'Повне досьє безкоштовно на DOVIRA.',
            'green' => 'Повне досьє і відгуки на DOVIRA.',
            default => 'Перевірте перед зверненням на DOVIRA.',
        };
    }

    /**
     * Сніпет для профілю без досьє, але з AI-аналізом відгуків
     * (ai_review_summary): конкретні теми «хвалять/скарги» з аспектів.
     * Повертає null, якщо аналізу нема — тоді працює стандартна гілка.
     *
     * @param  array<string, mixed>|null  $aiReviewSummary
     * @return array<int, string>|null
     */
    private static function buildAiSummarySegments(
        string $name,
        ?array $aiReviewSummary,
        bool $ratingVisible,
        float $rating,
        int $reviewsCount
    ): ?array {
        if (! is_array($aiReviewSummary) || $aiReviewSummary === []) {
            return null;
        }

        $aspects = collect((array) ($aiReviewSummary['aspects'] ?? []))
            ->filter(fn ($aspect) => is_array($aspect) && filled($aspect['topic'] ?? null));

        $praise = $aspects->where('sentiment', 'positive')->pluck('topic')
            ->map(fn ($topic) => self::lowercaseSentenceItem(trim((string) $topic)))
            ->take(2);
        $complaints = $aspects->where('sentiment', 'negative')->pluck('topic')
            ->map(fn ($topic) => self::lowercaseSentenceItem(trim((string) $topic)))
            ->take(2);

        if ($praise->isEmpty() && $complaints->isEmpty()) {
            $summaryText = self::normalizeText((string) ($aiReviewSummary['summary'] ?? ''));

            if ($summaryText === null) {
                return null;
            }

            return array_values(array_filter([
                self::ensureSentence((string) Str::limit("AI-аналіз відгуків: {$summaryText}", 110, '...')),
                self::buildCompactProofSentence($ratingVisible, $rating, $reviewsCount),
                'Повний AI-розбір на DOVIRA.',
            ]));
        }

        $hook = $complaints->isNotEmpty()
            ? "AI розібрав відгуки про {$name}: що хвалять і на що скаржаться."
            : "AI розібрав відгуки про {$name}: за що хвалять клієнти.";

        $detail = self::ensureSentence(self::ucfirstSentence(implode('; ', array_filter([
            $praise->isNotEmpty() ? 'хвалять: ' . $praise->implode(', ') : null,
            $complaints->isNotEmpty() ? 'скарги: ' . $complaints->implode(', ') : null,
        ]))));

        return array_values(array_filter([
            $hook,
            $detail,
            self::buildCompactProofSentence($ratingVisible, $rating, $reviewsCount),
            'Повний AI-розбір на DOVIRA.',
        ]));
    }

    /**
     * Компактний соц-доказ для досьє-сніпета: цифри без повтору назви,
     * щоб лишити місце під CTA у ліміті 160 символів.
     */
    private static function buildCompactProofSentence(bool $ratingVisible, float $rating, int $reviewsCount): string
    {
        if ($ratingVisible && $rating > 0 && $reviewsCount > 0) {
            return 'Рейтинг ' . self::formatRatingValue($rating) . '/5 · '
                . $reviewsCount . ' ' . self::reviewsWord($reviewsCount) . ' реальних клієнтів.';
        }

        if ($reviewsCount > 0) {
            return $reviewsCount . ' ' . self::reviewsWord($reviewsCount) . ' реальних клієнтів.';
        }

        return 'Реальні відгуки та перевірка з відкритих джерел.';
    }

    /**
     * @param  array<int, string>  $services
     */
    private static function buildProfileSentence(string $name, array $services, ?string $aboutSentence): string
    {
        if ($aboutSentence !== null) {
            return $aboutSentence;
        }

        $serviceNames = self::serviceNames($services);

        if ($serviceNames->isNotEmpty()) {
            return self::ucfirstSentence($serviceNames->implode(', ')) . '. Контакти на DOVIRA.';
        }

        return 'Перевірте репутацію, контакти та досвід клієнтів.';
    }

    /**
     * @param  array<int, string>  $services
     */
    private static function buildPreviousSearchProfileSentence(string $name, array $services, ?string $aboutSentence): string
    {
        if ($aboutSentence !== null) {
            return "{$name}: {$aboutSentence}";
        }

        $serviceNames = self::serviceNames($services);

        if ($serviceNames->isNotEmpty()) {
            return "{$name}: " . $serviceNames->implode(', ') . '. Контакти й відгуки на DOVIRA.';
        }

        return "{$name}: інформація про профіль, контакти та репутацію.";
    }

    /**
     * @param  array<int, string>  $services
     */
    private static function serviceNames(array $services): \Illuminate\Support\Collection
    {
        return collect($services)
            ->map(fn ($service) => self::normalizeText(is_string($service) ? $service : null))
            ->filter()
            ->unique(fn ($service) => mb_strtolower((string) $service))
            ->take(3)
            ->map(fn ($service) => self::lowercaseSentenceItem((string) $service))
            ->values();
    }

    /**
     * @param  array<int, string>  $segments
     */
    private static function joinSegmentsWithinLimit(array $segments, int $limit): string
    {
        $result = '';

        foreach ($segments as $segment) {
            $segment = self::normalizeText($segment);
            if ($segment === null) {
                continue;
            }

            $candidate = trim($result === '' ? $segment : "{$result} {$segment}");

            if (mb_strlen($candidate) <= $limit) {
                $result = $candidate;
                continue;
            }

            if ($result === '') {
                $result = Str::limit($segment, $limit, '...');
            }

            break;
        }

        return $result;
    }

    private static function categoryKeyword(?string $categoryName): ?string
    {
        $normalized = self::normalizeCategoryName($categoryName);
        if ($normalized === null) {
            return null;
        }

        return match ($normalized) {
            'Адвокати' => 'адвокат',
            'Юристи' => 'юрист',
            'Стоматологи' => 'стоматолог',
            'Нотаріуси' => 'нотаріус',
            'Компанії' => 'компанія',
            'Магазини' => 'магазин',
            'Сервіси' => 'сервіс',
            'Спеціалісти' => 'спеціаліст',
            'Lawyers' => 'lawyer',
            'Dentists' => 'dentist',
            'Notaries' => 'notary',
            default => mb_strtolower($normalized),
        };
    }

    private static function keywordGenitive(string $keyword): string
    {
        return match (mb_strtolower($keyword)) {
            'адвокат' => 'адвоката',
            'юрист' => 'юриста',
            'стоматолог' => 'стоматолога',
            'нотаріус' => 'нотаріуса',
            'компанія', 'компанія ' => 'компанії',
            'магазин' => 'магазину',
            'сервіс' => 'сервісу',
            'спеціаліст' => 'спеціаліста',
            default => $keyword,
        };
    }

    private static function supportingSeoSentence(?string $keyword): string
    {
        return match (mb_strtolower((string) $keyword)) {
            'адвокат', 'юрист', 'стоматолог', 'нотаріус', 'спеціаліст' => 'Читайте досвід клієнтів на DOVIRA.',
            'компанія', 'магазин', 'сервіс' => 'Читайте досвід клієнтів про компанію на DOVIRA.',
            default => 'Читайте досвід клієнтів на DOVIRA.',
        };
    }

    private static function previousSearchSupportingSeoSentence(?string $keyword): string
    {
        return match (mb_strtolower((string) $keyword)) {
            'адвокат', 'юрист', 'стоматолог', 'нотаріус', 'спеціаліст' => 'Дивіться контакти, відгуки клієнтів і деталі профілю.',
            'компанія', 'магазин', 'сервіс' => 'Дивіться контакти, відгуки клієнтів і деталі компанії.',
            default => 'Дивіться контакти, відгуки та деталі профілю.',
        };
    }

    private static function profileSentenceHasCallToAction(string $sentence): bool
    {
        return Str::contains(mb_strtolower($sentence), ['контакти', 'відгуки']);
    }

    private static function hasProfileSearchSignals(string $value): bool
    {
        $normalized = mb_strtolower($value);

        return str_contains($normalized, 'відгу')
            && (
                str_contains($normalized, 'рейтинг')
                || str_contains($normalized, 'оцін')
                || str_contains($normalized, 'досьє')
            );
    }

    private static function hasPreferredSearchTitleSignals(string $value, ?string $name): bool
    {
        $normalized = self::normalizeComparisonValue($value);
        $normalizedName = self::normalizeComparisonValue($name);

        if ($normalized === null || $normalizedName === null) {
            return false;
        }

        return str_contains($normalized, $normalizedName)
            && str_contains($normalized, 'відгу')
            && (
                str_starts_with($normalized, $normalizedName . ' відгу')
                || str_starts_with($normalized, 'відгуки про ' . $normalizedName)
            );
    }

    private static function hasDossierSearchSignals(string $value): bool
    {
        return str_contains(mb_strtolower($value), 'досьє');
    }

    private static function cityLocativePhrase(?string $city): ?string
    {
        $city = self::normalizeText($city);

        if ($city === null) {
            return null;
        }

        $normalized = mb_strtolower($city);
        $locative = match ($normalized) {
            'київ' => 'Києві',
            'одеса' => 'Одесі',
            'львів' => 'Львові',
            'харків' => 'Харкові',
            'дніпро' => 'Дніпрі',
            'запоріжжя' => 'Запоріжжі',
            'вінниця' => 'Вінниці',
            'полтава' => 'Полтаві',
            'черкаси' => 'Черкасах',
            'чернігів' => 'Чернігові',
            'суми' => 'Сумах',
            'рівне' => 'Рівному',
            'житомир' => 'Житомирі',
            'луцьк' => 'Луцьку',
            'ужгород' => 'Ужгороді',
            'івано-франківськ' => 'Івано-Франківську',
            'тернопіль' => 'Тернополі',
            'хмельницький' => 'Хмельницькому',
            'чернівці' => 'Чернівцях',
            'миколаїв' => 'Миколаєві',
            'херсон' => 'Херсоні',
            'кропивницький' => 'Кропивницькому',
            default => null,
        };

        if ($locative === null) {
            return "у місті {$city}";
        }

        return (Str::startsWith(mb_strtolower($locative), ['о', 'і', 'а', 'е', 'у', 'я', 'ю', 'є', 'ї'])
            ? 'в '
            : 'у ') . $locative;
    }

    private static function nameContainsKeyword(string $name, string $keyword): bool
    {
        $normalizedName = mb_strtolower($name);
        $normalizedKeyword = mb_strtolower($keyword);

        if (Str::contains($normalizedName, $normalizedKeyword)) {
            return true;
        }

        // Українські відмінки: назва «CleanExpress, хімчистка» і ключ
        // «хімчистки» — це одне й те саме слово, а точне порівняння давало
        // збіг «false» і тайтл «…хімчистка, хімчистки — Одеса». Порівнюємо
        // за коренем (без двох останніх літер), але лише для довгих слів,
        // щоб не склеїти різні короткі («ліки» / «лікар»).
        if (mb_strlen($normalizedKeyword) >= 7) {
            $stem = mb_substr($normalizedKeyword, 0, mb_strlen($normalizedKeyword) - 2);
            if (Str::contains($normalizedName, $stem)) {
                return true;
            }
        }

        // Багатослівний ключ («агентства нерухомості»): цілісний стем вище не
        // спрацьовує, коли відмінок відрізняється в ПЕРШОМУ слові («агентство»
        // в назві vs «агентства» в категорії) — тоді сніпет дублює майже
        // однакові фрази. Вважаємо ключ присутнім, якщо КОЖНЕ його слово
        // знайдено в назві (довгі — за коренем без двох літер, короткі — точно).
        $keywordWords = preg_split('/\s+/u', $normalizedKeyword) ?: [];
        if (count($keywordWords) >= 2) {
            $allWordsPresent = true;
            foreach ($keywordWords as $word) {
                $needle = mb_strlen($word) >= 7 ? mb_substr($word, 0, mb_strlen($word) - 2) : $word;
                if (! Str::contains($normalizedName, $needle)) {
                    $allWordsPresent = false;
                    break;
                }
            }
            if ($allWordsPresent) {
                return true;
            }
        }

        return match ($normalizedKeyword) {
            'адвокат' => Str::contains($normalizedName, ['адвокат', 'адвокатка']),
            'юрист' => Str::contains($normalizedName, ['юрист', 'юридич']),
            'стоматолог' => Str::contains($normalizedName, ['стоматолог', 'стоматологія']),
            'нотаріус' => Str::contains($normalizedName, ['нотаріус', 'нотаріальна']),
            default => false,
        };
    }

    private static function ensureSentence(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return $value;
        }

        if (preg_match('/[.!?…]$/u', $value) === 1) {
            return $value;
        }

        return $value . '.';
    }

    private static function normalizeText(?string $value): ?string
    {
        $plain = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace('/\s+/u', ' ', trim($plain));

        return $normalized === '' ? null : $normalized;
    }

    private static function normalizeCategoryName(?string $value): ?string
    {
        $normalized = self::normalizeText($value);

        if ($normalized === null || $normalized === 'Без категорії') {
            return null;
        }

        return $normalized;
    }

    private static function normalizeComparisonValue(?string $value): ?string
    {
        $normalized = self::normalizeText($value);

        return $normalized === null ? null : mb_strtolower($normalized);
    }

    private static function lowercaseSentenceItem(string $value): string
    {
        $first = mb_substr($value, 0, 1);

        if (preg_match('/^[А-ЯІЇЄҐ]$/u', $first) !== 1) {
            return $value;
        }

        return mb_strtolower($first) . mb_substr($value, 1);
    }

    private static function ucfirstSentence(string $value): string
    {
        $first = mb_substr($value, 0, 1);

        return mb_strtoupper($first) . mb_substr($value, 1);
    }

    private static function isLikelyPlaceholderText(string $value): bool
    {
        return preg_match('/(?:\btest\b|\bdemo\b|lorem ipsum|placeholder|заглуш|тестов|для перевірк|перевірк(?:а|и)? збереженн)/iu', $value) === 1;
    }
}
