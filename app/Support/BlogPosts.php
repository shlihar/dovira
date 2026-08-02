<?php

declare(strict_types=1);

namespace App\Support;

/**
 * File-based blog registry: the site must stay up on hosting without a
 * database, so posts live in code and article bodies are Blade partials
 * in resources/views/static/blog/posts/{slug}.blade.php.
 */
final class BlogPosts
{
    /**
     * @return array<int, array<string, mixed>> newest first
     */
    public static function all(): array
    {
        static $posts = null;

        if ($posts !== null) {
            return $posts;
        }

        $posts = [
            [
                'slug' => 'dovira-chy-google-vidhuky',
                'title' => 'Dovira чи Google-відгуки: де шукати правду про бізнес',
                'seo_title' => 'Dovira чи Google-відгуки — де читати відгуки | Блог DOVIRA',
                'description' => 'Порівняння Dovira і Google-відгуків: модерація, накрутка, відповіді бізнесу, спеціалісти без адреси та прозорість рейтингу. Як користуватися обома джерелами.',
                'excerpt' => 'Чим профіль на Dovira відрізняється від оцінки в Google-картках і як звірити обидва джерела, щоб не помилитися перед оплатою.',
                'category' => 'Покупцям',
                'category_slug' => 'buyers',
                'icon' => 'fa-solid fa-code-compare',
                'accent' => 'blue',
                'published_at' => '2026-08-02',
                'updated_at' => '2026-08-02',
                'reading_minutes' => 7,
            ],
            [
                'slug' => 'de-chytaty-chesni-vidhuky-v-ukraini',
                'title' => 'Де читати чесні відгуки про компанії в Україні: 6 майданчиків',
                'seo_title' => 'Де читати чесні відгуки про компанії в Україні | Блог DOVIRA',
                'description' => 'Огляд 6 майданчиків із відгуками про бізнес і спеціалістів в Україні: Google, Dovira, маркетплейси, соцмережі, форуми та сайти компаній — плюси й мінуси кожного.',
                'excerpt' => 'Де в Україні реально читають відгуки про бізнес — 6 майданчиків із чесними плюсами й мінусами та правилом перехресної перевірки.',
                'category' => 'Покупцям',
                'category_slug' => 'buyers',
                'icon' => 'fa-solid fa-list-check',
                'accent' => 'violet',
                'published_at' => '2026-08-01',
                'updated_at' => '2026-08-01',
                'reading_minutes' => 7,
            ],
            [
                'slug' => 'yak-pereviryty-prodavtsia-instagram-olx',
                'title' => 'Як перевірити продавця в Instagram і на OLX перед передоплатою',
                'seo_title' => 'Як перевірити продавця в Instagram і OLX | Блог DOVIRA',
                'description' => 'Покрокова перевірка продавця в Instagram і на OLX перед передоплатою: відгуки, вік акаунта, безпечна оплата, ознаки фейкового магазину та що робити, якщо ошукали.',
                'excerpt' => 'Як за кілька хвилин відсіяти шахрая в Instagram чи на OLX до того, як переказати передоплату, — чекліст і що робити, якщо вже втратили гроші.',
                'category' => 'Покупцям',
                'category_slug' => 'buyers',
                'icon' => 'fa-solid fa-shield-halved',
                'accent' => 'blue',
                'published_at' => '2026-07-31',
                'updated_at' => '2026-07-31',
                'reading_minutes' => 7,
            ],
            [
                'slug' => 'yak-vybraty-advokata',
                'title' => 'Як вибрати адвоката: покроковий гід без помилок',
                'seo_title' => 'Як вибрати адвоката в Україні — покроковий гід | Блог DOVIRA',
                'description' => 'Як знайти надійного адвоката: перевірка свідоцтва, спеціалізація, відгуки клієнтів, питання на першій консультації та ознаки, що юристу не варто довіряти.',
                'excerpt' => 'Від перевірки свідоцтва до першої консультації: як обрати адвоката, який справді допоможе, а не просто візьме гонорар.',
                'category' => 'Покупцям',
                'category_slug' => 'buyers',
                'icon' => 'fa-solid fa-scale-balanced',
                'accent' => 'blue',
                'published_at' => '2026-07-05',
                'updated_at' => '2026-07-05',
                'reading_minutes' => 8,
            ],
            [
                'slug' => 'yak-pokrashchyty-reputatsiiu-profiliu',
                'title' => 'Як власнику профілю підняти рейтинг і довіру на DOVIRA',
                'seo_title' => 'Як підняти рейтинг профілю компанії на DOVIRA | Блог DOVIRA',
                'description' => 'Практичний план для власників профілів на DOVIRA: заповнення профілю, статуси довіри, робота з відгуками та відповідями — що реально впливає на рейтинг і звернення.',
                'excerpt' => 'Покроковий план: від заповненого профілю і статусів довіри до системної роботи з відгуками, яка приводить клієнтів.',
                'category' => 'Бізнесу',
                'category_slug' => 'business',
                'icon' => 'fa-solid fa-arrow-trend-up',
                'accent' => 'green',
                'published_at' => '2026-07-04',
                'updated_at' => '2026-07-04',
                'reading_minutes' => 8,
            ],
            [
                'slug' => 'shcho-take-dovira',
                'title' => 'Що таке DOVIRA: хто ми, як працюємо і навіщо це все',
                'seo_title' => 'Що таке DOVIRA — як працює платформа відгуків | Блог DOVIRA',
                'description' => 'DOVIRA — українська платформа відгуків про компанії та спеціалістів. Розповідаємо, як працює модерація, звідки беруться профілі та чому нам можна довіряти.',
                'excerpt' => 'Знайомство з платформою: як з\'являються профілі, як модеруються відгуки і що ми робимо, щоб рейтингам можна було вірити.',
                'category' => 'Платформа',
                'category_slug' => 'platform',
                'icon' => 'fa-solid fa-handshake',
                'accent' => 'violet',
                'published_at' => '2026-07-03',
                'updated_at' => '2026-07-03',
                'reading_minutes' => 6,
            ],
            [
                'slug' => 'yak-korystuvatysia-pro-akauntom',
                'title' => 'PRO-акаунт на DOVIRA: бейджі, кабінет і аналітика — повна інструкція',
                'seo_title' => 'Як користуватися PRO-акаунтом DOVIRA — інструкція | Блог DOVIRA',
                'description' => 'Повна інструкція по PRO-акаунту DOVIRA: що означають бейджі «Перевірений акаунт» і PRO, як підтвердити профіль, відповідати на відгуки та читати аналітику.',
                'excerpt' => 'Що означають бейджі довіри, як підтвердити право на профіль і вичавити максимум із PRO-кабінету — розбираємо по кроках.',
                'category' => 'Платформа',
                'category_slug' => 'platform',
                'icon' => 'fa-solid fa-gem',
                'accent' => 'amber',
                'published_at' => '2026-07-02',
                'updated_at' => '2026-07-02',
                'reading_minutes' => 9,
            ],
            [
                'slug' => 'yak-pereviryty-kompaniyu-pered-zamovlennyam',
                'title' => 'Як перевірити компанію перед замовленням: 7 простих кроків',
                'seo_title' => 'Як перевірити компанію перед замовленням — 7 кроків | Блог DOVIRA',
                'description' => 'Покрокова інструкція, як перевірити компанію чи інтернет-магазин перед покупкою: відгуки, реєстраційні дані, сайт, соцмережі та ознаки шахрайства.',
                'excerpt' => 'Покрокова інструкція, як за 10 хвилин зрозуміти, чи можна довіряти компанії: від відгуків до реєстраційних даних.',
                'category' => 'Покупцям',
                'category_slug' => 'buyers',
                'icon' => 'fa-solid fa-magnifying-glass',
                'accent' => 'blue',
                'published_at' => '2026-07-01',
                'updated_at' => '2026-07-01',
                'reading_minutes' => 7,
            ],
            [
                'slug' => 'feikovi-vidhuky-yak-rozpiznaty',
                'title' => 'Фейкові відгуки: 8 ознак, як їх розпізнати',
                'seo_title' => 'Як розпізнати фейкові відгуки — 8 ознак | Блог DOVIRA',
                'description' => 'Фейкові відгуки вводять в оману мільйони покупців. Розповідаємо про 8 ознак накручених відгуків і як перевірити, чи можна довіряти рейтингу компанії.',
                'excerpt' => 'Накручені відгуки — головна проблема онлайн-довіри. Вчимося відрізняти реальний досвід клієнтів від замовних текстів.',
                'category' => 'Покупцям',
                'category_slug' => 'buyers',
                'icon' => 'fa-solid fa-user-secret',
                'accent' => 'violet',
                'published_at' => '2026-06-24',
                'updated_at' => '2026-06-24',
                'reading_minutes' => 6,
            ],
            [
                'slug' => 'yak-vidpovidaty-na-nehatyvni-vidhuky',
                'title' => 'Як бізнесу відповідати на негативні відгуки: шаблони і приклади',
                'seo_title' => 'Як відповідати на негативні відгуки — шаблони | Блог DOVIRA',
                'description' => 'Правильна відповідь на негативний відгук повертає до 45% незадоволених клієнтів. Готові шаблони відповідей, приклади та помилки, яких слід уникати.',
                'excerpt' => 'Негативний відгук — це не вирок, а можливість. Розбираємо структуру правильної відповіді з готовими шаблонами.',
                'category' => 'Бізнесу',
                'category_slug' => 'business',
                'icon' => 'fa-solid fa-comments',
                'accent' => 'amber',
                'published_at' => '2026-06-17',
                'updated_at' => '2026-07-02',
                'reading_minutes' => 8,
            ],
            [
                'slug' => 'onlain-reputatsiia-biznesu-hid',
                'title' => 'Онлайн-репутація бізнесу: повний гід для власників у 2026 році',
                'seo_title' => 'Онлайн-репутація бізнесу: повний гід 2026 | Блог DOVIRA',
                'description' => 'Що таке онлайн-репутація, як вона впливає на продажі та як нею керувати: моніторинг відгуків, робота з рейтингом, публічні відповіді та типові помилки.',
                'excerpt' => '93% покупців читають відгуки перед покупкою. Розбираємо, з чого складається репутація бізнесу в інтернеті та як нею керувати.',
                'category' => 'Бізнесу',
                'category_slug' => 'business',
                'icon' => 'fa-solid fa-shield-halved',
                'accent' => 'green',
                'published_at' => '2026-06-10',
                'updated_at' => '2026-06-10',
                'reading_minutes' => 9,
            ],
            [
                'slug' => 'yak-napysaty-korysnyy-vidhuk',
                'title' => 'Як написати корисний відгук, якому довірятимуть',
                'seo_title' => 'Як написати корисний відгук про компанію | Блог DOVIRA',
                'description' => 'Хороший відгук допомагає тисячам людей зробити правильний вибір. Проста структура корисного відгуку: факти, деталі, баланс і докази.',
                'excerpt' => 'Відгук «все сподобалось» нікому не допомагає. Проста структура, яка робить ваш відгук справді корисним для інших.',
                'category' => 'Покупцям',
                'category_slug' => 'buyers',
                'icon' => 'fa-solid fa-pen-nib',
                'accent' => 'blue',
                'published_at' => '2026-06-03',
                'updated_at' => '2026-06-03',
                'reading_minutes' => 5,
            ],
            [
                'slug' => 'yak-otrymaty-bilshe-vidhukiv-vid-kliientiv',
                'title' => 'Як отримати більше відгуків від клієнтів: 9 робочих способів',
                'seo_title' => 'Як отримати більше відгуків від клієнтів — 9 способів | Блог DOVIRA',
                'description' => 'Більшість задоволених клієнтів не залишають відгуки, бо їх ніхто не просить. 9 етичних способів зібрати більше реальних відгуків для вашого бізнесу.',
                'excerpt' => 'Задоволені клієнти мовчать, незадоволені — пишуть. Як змінити цей баланс на свою користь — без накруток і сірих схем.',
                'category' => 'Бізнесу',
                'category_slug' => 'business',
                'icon' => 'fa-solid fa-star-half-stroke',
                'accent' => 'violet',
                'published_at' => '2026-05-27',
                'updated_at' => '2026-05-27',
                'reading_minutes' => 7,
            ],
        ];

        return $posts;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $slug): ?array
    {
        foreach (self::all() as $post) {
            if ($post['slug'] === $slug) {
                return $post;
            }
        }

        return null;
    }

    /**
     * Same-category posts first, then the rest — excluding the given slug.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function related(string $slug, int $limit = 3): array
    {
        $current = self::find($slug);

        if ($current === null) {
            return [];
        }

        $others = array_values(array_filter(
            self::all(),
            fn (array $post): bool => $post['slug'] !== $slug
        ));

        usort($others, function (array $a, array $b) use ($current): int {
            $aSame = $a['category_slug'] === $current['category_slug'] ? 0 : 1;
            $bSame = $b['category_slug'] === $current['category_slug'] ? 0 : 1;

            return $aSame <=> $bSame;
        });

        return array_slice($others, 0, $limit);
    }

    /**
     * @return array<string, string> category_slug => label
     */
    public static function categories(): array
    {
        $categories = [];

        foreach (self::all() as $post) {
            $categories[$post['category_slug']] = $post['category'];
        }

        return $categories;
    }
}
