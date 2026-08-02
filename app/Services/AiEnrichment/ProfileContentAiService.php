<?php

namespace App\Services\AiEnrichment;

use App\Models\Profile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AI-контент для сторінок профілів: переписування описів своїми словами
 * (та сама суть, оригінальний текст) і підсумки відгуків. Використовує той
 * самий OpenAI Responses API, що й AI-збагачення (config/ai_enrichment.php).
 */
class ProfileContentAiService
{
    /**
     * Usage останнього запиту до OpenAI (input_tokens, output_tokens...).
     *
     * @var array<string, mixed>
     */
    public array $lastUsage = [];

    /**
     * Переписує опис профілю оригінальним текстом зі збереженням фактів.
     *
     * @return array{description: string, seo_description: string}|null
     */
    public function rewriteDescription(Profile $profile): ?array
    {
        $description = trim((string) $profile->description);

        if ($description === '') {
            return null;
        }

        $context = [
            'name' => $profile->name,
            'city' => $profile->city,
            'categories' => $profile->categories->pluck('name')->values()->all(),
            'services' => $profile->services->pluck('name')->take(15)->values()->all(),
            'current_description' => Str::limit($description, 2200, ''),
            'current_seo_description' => (string) $profile->seo_description,
        ];

        $systemPrompt = <<<'PROMPT'
Ти — редактор українського каталогу компаній і спеціалістів DOVIRA.
Перепиши опис профілю повністю своїми словами так, щоб текст був оригінальним,
але суть, факти і тон залишилися тими самими.

Жорсткі правила:
- НЕ додавай жодних фактів, яких немає у вхідних даних (жодних вигаданих років
  досвіду, нагород, цифр, обіцянок якості).
- НЕ прибирай суттєвих фактів з опису.
- Не використовуй оцінних суперлативів від себе ("найкращий", "лідер ринку"),
  якщо їх не було в оригіналі.
- Пиши українською, природно і стримано, без канцеляриту й переліків із кліше.
- description: 2–4 абзаци звичайного тексту без markdown і заголовків.
- seo_description: один речення-два до 160 символів для метатегу description.
Поверни JSON за схемою.
PROMPT;

        $result = $this->request($systemPrompt, $context, 'dovira_profile_description_rewrite', [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['description', 'seo_description'],
            'properties' => [
                'description' => ['type' => 'string'],
                'seo_description' => ['type' => 'string'],
            ],
        ]);

        $newDescription = trim((string) ($result['description'] ?? ''));
        $newSeoDescription = trim((string) ($result['seo_description'] ?? ''));

        if ($newDescription === '' || mb_strlen($newDescription) < 80) {
            return null;
        }

        return [
            'description' => $newDescription,
            'seo_description' => $newSeoDescription !== '' ? Str::limit($newSeoDescription, 160, '') : (string) $profile->seo_description,
        ];
    }

    /**
     * Генерує УНІКАЛЬНИЙ опис для профілю без вхідного тексту (лише назва,
     * місто, категорія, послуги). На відміну від шаблону, кожен опис має
     * природно відрізнятись структурою і формулюваннями — щоб уникнути
     * дубль-контенту. Не вигадує фактів, не додає службових приміток.
     *
     * @return array{description: string, seo_description: string}|null
     */
    public function composeProfileDescription(Profile $profile): ?array
    {
        $categories = $profile->categories->pluck('name')->values()->all();
        $services = $profile->services->pluck('name')->take(15)->values()->all();

        $context = [
            'name' => $profile->name,
            'city' => $profile->city,
            'categories' => $categories,
            'services' => $services,
            'has_phone' => filled($profile->phone),
            'has_website' => filled($profile->website),
            // Легка варіативність, щоб описи не збігались структурою.
            'variation_seed' => (int) $profile->id % 5,
        ];

        $systemPrompt = <<<'PROMPT'
Ти — редактор українського каталогу компаній і спеціалістів DOVIRA.
Напиши короткий природний опис профілю на основі базових даних
(назва, місто, категорія, послуги). Опис має бути ОРИГІНАЛЬНИМ.

Жорсткі правила:
- НЕ вигадуй фактів: жодного досвіду в роках, нагород, кількості справ,
  оцінок якості («найкращий», «надійний»), сайтів, цін — якщо їх нема у вхідних.
- ЗАБОРОНЕНО службові примітки в тексті («потребує перевірки», «створено на
  основі імпорту», «профіль» як мета-слово тощо).
- НЕ використовуй фіксований шаблон. Варіюй структуру, порядок і формулювання
  так, щоб описи різних профілів не були схожими між собою. variation_seed —
  підказка обрати інший ракурс/початок (0–4), не згадуй її в тексті.
- Пиши стримано, природною українською, 2–3 речення, без markdown і заголовків.
- Спирайся на реальні напрями/послуги, якщо вони є; якщо даних обмаль —
  опиши сферу діяльності загально, але живою мовою, без «води».
- seo_description: одне-два речення до 160 символів для метатегу.
Поверни JSON за схемою.
PROMPT;

        $result = $this->request($systemPrompt, $context, 'dovira_profile_description_compose', [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['description', 'seo_description'],
            'properties' => [
                'description' => ['type' => 'string'],
                'seo_description' => ['type' => 'string'],
            ],
        ]);

        $newDescription = trim((string) ($result['description'] ?? ''));
        $newSeoDescription = trim((string) ($result['seo_description'] ?? ''));

        if ($newDescription === '' || mb_strlen($newDescription) < 60) {
            return null;
        }

        return [
            'description' => $newDescription,
            'seo_description' => $newSeoDescription !== '' ? Str::limit($newSeoDescription, 160, '') : (string) $profile->seo_description,
        ];
    }

    /**
     * AI-досьє: розгорнутий репутаційний матеріал про профіль, зібраний
     * веб-пошуком з відкритих джерел (реєстри, суди, новини, сайт компанії).
     * Повертає markdown-текст і список використаних джерел.
     *
     * @return array{dossier: string, sources: array<int, string>}|null
     */
    public function composeDossier(Profile $profile): ?array
    {
        $context = [
            'name' => $profile->name,
            'city' => $profile->city,
            'categories' => $profile->categories->pluck('name')->values()->all(),
            'website' => $profile->website,
            'description' => \Illuminate\Support\Str::limit((string) $profile->description, 800),
            'rating' => (float) $profile->rating_avg,
            'reviews_count' => (int) $profile->reviews_count,
        ];

        $systemPrompt = <<<'PROMPT'
Ти — розслідувач-редактор українського каталогу DOVIRA. Підготуй ДОСЬЄ про
спеціаліста/компанію для потенційного клієнта: чесний журналістський
бекграундер на основі активного веб-пошуку (зроби кілька різних запитів).

Де шукати:
- Офіційні реєстри: для адвокатів ОБОВ'ЯЗКОВО відкрий профіль в ЄРАУ
  (site:erau.unba.org.ua «ім'я») — САМЕ ТАМ номер і дата свідоцтва,
  дисциплінарні стягнення і зупинення діяльності. Дзеркала-агрегатори
  (uadvokat, protocol і под.) НЕ показують стягнень — робити з них
  висновок «стягнень немає» ЗАБОРОНЕНО. Для компаній — ЄДР/opendatabot.
- Судові рішення (reyestr.court.gov.ua, court.opendatabot.ua): справи, де
  профіль — СТОРОНА (суди з клієнтами, стягнення гонорарів), і показові
  справи, які він вів.
- ЗМІ та новини: резонансні події, конфлікти, розслідування.
- Сайт і соцмережі профілю: заявлені результати, обіцянки, реклама — щоб
  ЗВІРИТИ їх із фактами з реєстрів і фінальними рішеннями судів.

Структура тексту (пункти без фактів — мовчки пропускай):
1. Лід одним абзацом: **хто це** (жирним ім'я + роль + компанія), місто,
   рік отримання свідоцтва/заснування, з чим працює, як просувається.
2. «**Найважливіший репутаційний факт:**» — дисциплінарні стягнення чи
   зупинення діяльності: період словами, який орган ухвалив. Якщо причина
   у відкритому записі не вказана — чесно зазнач це.
3. Помітні справи: що заявляв сам профіль ПРОТИ остаточного рішення суду
   (суми **жирним**) + одне речення-висновок для читача («Це показовий
   приклад того, чому…»).
4. Резонансна подія за участю профілю та її офіційні наслідки — як
   міні-історія («У червні 2025 року … сам опинився в центрі…»).
5. «**Що варто врахувати перед укладенням договору:**» — суди з власними
   клієнтами, підхід до стягнення оплати; виважено: «це не свідчить про
   порушення, але…» + практична порада, що читати уважно.
6. Рекламні твердження («99% успіху», «гарантований результат») і чи є їм
   публічне підтвердження; як їх сприймати.
7. «**Загалом:**» — 1–2 речення: сильні сторони + факти, які клієнт мусить
   знати.

Стиль — журналістський бекграундер для ЗВИЧАЙНОГО читача, не юриста:
- ЖОДНИХ номерів справ, свідоцтв, ЄДРПОУ, статей законів і назв судових
  інстанцій-абревіатур. «У справі про незаконне переслідування…», а не
  «у справі №495/7377/21 за п.3 ч.1 ст.31».
- Суми скорочено: **1,5 млн грн**, **750 тис. грн** — не «1 500 000 грн».
- Дати словами: «із 25 лютого до 25 травня 2026 року», «у червні 2025-го».
- Абзаци по 2–4 речення, одна думка на абзац. Загалом 280–380 слів.
- Жирних міні-заголовків не вигадуй: лише три мітки зі структури
  («Найважливіший репутаційний факт:», «Що варто врахувати…», «Загалом:»)
  і жирні акценти на іменах/сумах/цифрах.

Жорсткі правила:
- ТІЛЬКИ факти, реально знайдені в джерелах. Кожна дата, сума, номер — зі
  знайденого документа. НЕ вигадуй, НЕ припускай, НЕ узагальнюй без бази.
- Стверджувати ВІДСУТНІСТЬ стягнень/справ можна лише якщо ти реально
  відкрив офіційний реєстр і їх там нема. Якщо офіційне джерело недоступне —
  просто пропусти цей пункт, без тверджень про відсутність.
- БЕЗ цитат-приписок у дужках типу «(site.ua)» — джерела лише в sources.
- Нейтральний тон: жодних «поганий/чудовий». Негатив і позитив подавай
  однаково спокійно, контекст замість вироків.
- Українською, 300–450 слів. Markdown: лише **жирний**. Зв'язні абзаци,
  БЕЗ маркованих списків, БЕЗ заголовків, БЕЗ посилань/URL у тексті.
- sources: усі використані URL (для внутрішньої перевірки).
- Якщо суттєвої інформації у відкритих джерелах немає — found_enough=false
  і порожнє досьє. Не пиши «води».
Поверни JSON за схемою.
PROMPT;

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['found_enough', 'dossier', 'sources'],
            'properties' => [
                'found_enough' => ['type' => 'boolean'],
                'dossier' => ['type' => 'string'],
                'sources' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
        $options = [
            'model' => (string) config('ai_enrichment.dossier.model', 'gpt-5.1'),
            'timeout' => (int) config('ai_enrichment.dossier.timeout', 600),
            'web_search' => true,
            'web_search_tool' => (string) config('ai_enrichment.dossier.web_search_tool', 'web_search'),
            'reasoning' => (string) config('ai_enrichment.dossier.reasoning_effort', 'medium'),
        ];

        try {
            $result = $this->request($systemPrompt, $context, 'dovira_profile_dossier', $schema, $options);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            // Невідома модель або тип пошук-інструмента на цьому акаунті —
            // один фолбек на консервативну пару.
            if (! in_array($e->response?->status(), [400, 404], true)) {
                throw $e;
            }
            $result = $this->request($systemPrompt, $context, 'dovira_profile_dossier', $schema, [
                ...$options,
                'model' => 'gpt-4.1',
                'web_search_tool' => 'web_search_preview',
                'reasoning' => null,
            ]);
        }

        $dossier = trim((string) ($result['dossier'] ?? ''));
        // Інлайн-посилання ([текст](url)) і цитати-приписки «(site.ua)»
        // прибираємо — джерела живуть окремо в sources.
        $dossier = trim((string) preg_replace([
            '/\[([^\]]+)\]\([^)]*\)/u',
            '/\(\s*[a-z0-9.-]+\.[a-z]{2,}(?:\/[^\s)]*)?\s*\)/iu',
        ], ['$1', ''], $dossier));

        if (! ($result['found_enough'] ?? false) || mb_strlen($dossier) < 200) {
            return null;
        }

        return [
            'dossier' => $dossier,
            'sources' => array_values(array_filter(array_map('trim', (array) ($result['sources'] ?? [])))),
        ];
    }

    /**
     * Аспектний аналіз відгуків: короткий природний підсумок + теми (аспекти)
     * з оцінкою настрою. Строго переказ реальних відгуків, без імен людей.
     *
     * @return array{summary: string, aspects: array<int, array{topic: string, sentiment: string, note: string}>, tags: array<int, string>}|null
     */
    public function summarizeReviews(Profile $profile): ?array
    {
        // Аналізуємо лише останні 50 відгуків — свіжіші й репрезентативніші
        // для поточного стану, і дешевші за токенами.
        $reviews = $profile->reviews()
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->limit(40)
            ->get(['rating', 'body']);

        $reviewItems = $reviews
            ->filter(fn ($review) => filled(trim((string) $review->body)))
            ->map(fn ($review) => [
                'rating' => (int) $review->rating,
                'text' => Str::limit(trim((string) $review->body), 320, '…'),
            ])
            ->values()
            ->all();

        if (count($reviewItems) < 3) {
            return null;
        }

        $context = [
            'profile_name' => $profile->name,
            'category' => $profile->categories->pluck('name')->first(),
            'reviews_total' => (int) $profile->reviews_count,
            'reviews_in_sample' => count($reviewItems),
            'reviews' => $reviewItems,
        ];

        $systemPrompt = <<<'PROMPT'
Ти аналізуєш відгуки клієнтів для української платформи відгуків DOVIRA
і формуєш аспектний підсумок для сторінки компанії/спеціаліста.

НАЙВАЖЛИВІШЕ ПРАВИЛО: НІКОЛИ не називай імен конкретних людей.
Заборонено згадувати будь-які імена, прізвища, по батькові чи звороти
на кшталт «адвокат Олена», «майстер Ірина». Якщо у відгуку хвалять
конкретну людину — переформулюй у знеособлену тему («уважне ставлення
персоналу», «професіоналізм спеціалістів»). Жодних власних імен у виводі.

Інші правила:
- Використовуй ТІЛЬКИ те, що написано у відгуках. Не вигадуй фактів,
  не додавай власних оцінок і рекомендацій («варто звернутись» заборонено).
- Пиши знеособленими темами, а не про окремих осіб.
- Будь чесним: якщо є повторювані скарги — відобрази їх аспектом з
  негативним настроєм, не згладжуй.

Поля виводу:
- summary: природний абзац 2–4 речення українською. Почни з назви компанії
  чи спеціаліста та сфери, опиши загальне враження клієнтів. Це основний
  текст для читача й для пошуку — пиши змістовно, без канцеляриту й без імен.
- aspects: 3–6 повторюваних тем із відгуків. Кожна:
    topic — коротка назва теми (1–3 слова): напр. «Якість роботи», «Ціни»,
      «Комунікація», «Строки», «Ставлення до клієнтів», «Результат».
    sentiment — одне з: "positive", "mixed", "negative" (загальний настрій
      клієнтів щодо цієї теми).
    note — коротка фраза (до 12 слів), що саме кажуть клієнти про цю тему.
      Без імен.
- tags: 3–6 дуже коротких ключових слів-тем (1–2 слова кожне) для швидкого
  сканування, напр. «професійність», «доступні ціни», «швидко». Без імен.
Поверни JSON за схемою.
PROMPT;

        $result = $this->request($systemPrompt, $context, 'dovira_profile_review_summary', [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'aspects', 'tags'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'aspects' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['topic', 'sentiment', 'note'],
                        'properties' => [
                            'topic' => ['type' => 'string'],
                            'sentiment' => ['type' => 'string', 'enum' => ['positive', 'mixed', 'negative']],
                            'note' => ['type' => 'string'],
                        ],
                    ],
                ],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ]);

        $summary = trim((string) ($result['summary'] ?? ''));

        if ($summary === '') {
            return null;
        }

        $aspects = collect((array) ($result['aspects'] ?? []))
            ->map(fn ($aspect) => [
                'topic' => trim((string) ($aspect['topic'] ?? '')),
                'sentiment' => in_array(($aspect['sentiment'] ?? ''), ['positive', 'mixed', 'negative'], true)
                    ? $aspect['sentiment']
                    : 'mixed',
                'note' => trim((string) ($aspect['note'] ?? '')),
            ])
            ->filter(fn ($aspect) => $aspect['topic'] !== '')
            ->take(6)
            ->values()
            ->all();

        return [
            'summary' => $summary,
            'aspects' => $aspects,
            'tags' => array_values(array_filter(array_map('trim', (array) ($result['tags'] ?? [])))),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function request(string $systemPrompt, array $context, string $schemaName, array $schema, array $options = []): array
    {
        $apiKey = (string) config('ai_enrichment.openai.api_key');

        if (blank($apiKey)) {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $payload = [
                'model' => (string) ($options['model'] ?? config('ai_enrichment.openai.model')),
                'input' => [
                    [
                        'role' => 'system',
                        'content' => [['type' => 'input_text', 'text' => $systemPrompt]],
                    ],
                    [
                        'role' => 'user',
                        'content' => [[
                            'type' => 'input_text',
                            'text' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]],
                    ],
                ],
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => $schemaName,
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
        ];

        if (! empty($options['reasoning'])) {
            $payload['reasoning'] = ['effort' => (string) $options['reasoning']];
        }

        if (! empty($options['web_search'])) {
            $payload['tools'] = [
                ['type' => (string) ($options['web_search_tool'] ?? config('ai_enrichment.openai.web_search_tool', 'web_search_preview'))],
            ];
            $payload['tool_choice'] = 'auto';
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout((int) ($options['timeout'] ?? 60))
            ->retry(1, 750, throw: false)
            ->post((string) config('ai_enrichment.openai.endpoint'), $payload)
            ->throw();

        $json = (array) $response->json();
        // Витрата токенів останнього запиту — для контролю бюджету
        // (найважливіше для досьє з веб-пошуком).
        $this->lastUsage = (array) data_get($json, 'usage', []);

        return $this->decodeResponse($json);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function decodeResponse(array $response): array
    {
        $outputText = data_get($response, 'output_text');

        if (blank($outputText)) {
            foreach ((array) data_get($response, 'output', []) as $output) {
                foreach ((array) data_get($output, 'content', []) as $content) {
                    $text = data_get($content, 'text') ?: data_get($content, 'output_text');

                    if (filled($text)) {
                        $outputText = $text;
                        break 2;
                    }
                }
            }
        }

        if (blank($outputText)) {
            throw new RuntimeException('OpenAI response does not contain output_text.');
        }

        $decoded = json_decode((string) $outputText, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI response is not valid JSON: '.Str::limit((string) $outputText, 300));
        }

        return $decoded;
    }
}
