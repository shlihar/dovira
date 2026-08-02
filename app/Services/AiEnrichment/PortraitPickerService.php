<?php

namespace App\Services\AiEnrichment;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Обирає портрет спеціаліста з фото на його сайті за допомогою vision-моделі.
 * Зображення надсилаються з detail=low — це фіксовано дешево за токенами.
 */
class PortraitPickerService
{
    /**
     * @return array{url: string, reason: string}|null
     */
    public function pickFromWebsite(string $websiteUrl, string $personName): ?array
    {
        $candidates = $this->collectImageCandidates($websiteUrl);

        if ($candidates === []) {
            return null;
        }

        return $this->pick($candidates, $personName);
    }

    /**
     * @param  array<int, string>  $imageUrls
     * @return array{url: string, reason: string}|null
     */
    public function pick(array $imageUrls, string $personName): ?array
    {
        $imageUrls = array_slice(array_values(array_unique($imageUrls)), 0, 8);
        if ($imageUrls === []) {
            return null;
        }

        $apiKey = (string) config('ai_enrichment.openai.api_key');
        if (blank($apiKey)) {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $content = [[
            'type' => 'input_text',
            'text' => "Це фото з сайту спеціаліста на ім'я «{$personName}». Обери ОДНЕ фото, яке є професійним портретом саме цієї людини (обличчя однієї особи, придатне для аватара профілю). Ігноруй логотипи, банери, групові фото, будівлі, скриншоти. Якщо портрета нема — поверни index = -1. Індексація з 0 у наданому порядку.",
        ]];
        foreach ($imageUrls as $url) {
            $content[] = ['type' => 'input_image', 'image_url' => $url, 'detail' => 'low'];
        }

        $response = Http::withToken($apiKey)->acceptJson()->asJson()->timeout(60)
            ->retry(1, 750, throw: false)
            ->post((string) config('ai_enrichment.openai.endpoint'), [
                'model' => (string) config('ai_enrichment.openai.model'),
                'input' => [['role' => 'user', 'content' => $content]],
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'portrait_pick',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['index', 'reason'],
                            'properties' => [
                                'index' => ['type' => 'integer'],
                                'reason' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ])
            ->throw();

        $decoded = $this->decode((array) $response->json());
        $index = (int) ($decoded['index'] ?? -1);

        if ($index < 0 || ! isset($imageUrls[$index])) {
            return null;
        }

        return ['url' => $imageUrls[$index], 'reason' => trim((string) ($decoded['reason'] ?? ''))];
    }

    /**
     * @return array<int, string>
     */
    public function collectImageCandidates(string $websiteUrl): array
    {
        $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])->timeout(20)->get($websiteUrl);
        if (! $response->successful()) {
            return [];
        }
        $html = $response->body();

        // Tilda: приводимо thumbnail (thb + /-/transform/) до повнорозмірного static.
        preg_match_all('#tildacdn\.net/(tild[0-9a-f-]+)/(?:-/[^/]+/)*([^"\'\s?]+\.(?:jpe?g|png))#i', $html, $tilda, PREG_SET_ORDER);
        $urls = [];
        foreach ($tilda as $m) {
            $urls[] = "https://static.tildacdn.net/{$m[1]}/{$m[2]}";
        }

        // Загальні <img src>, якщо сайт не на Tilda.
        if ($urls === []) {
            preg_match_all('#<img[^>]+src=["\']([^"\']+\.(?:jpe?g|png))["\']#i', $html, $imgs);
            foreach ($imgs[1] ?? [] as $src) {
                $urls[] = $this->absoluteUrl($src, $websiteUrl);
            }
        }

        // Відсіюємо очевидні лого/іконки/скани-документів за назвою файлу.
        $urls = array_filter($urls, fn ($u) => ! preg_match('#(logo|icon|favicon|sprite|badge|group|frame|pages-to-jpg|banner|cover)#i', $u));
        $urls = array_values(array_unique($urls));

        // Спершу файли, схожі на фото людей (IMG_, photo_, foto, portrait),
        // бо реальні портрети зазвичай далі в HTML, ніж декор.
        usort($urls, function ($a, $b): int {
            $score = fn ($u) => preg_match('#(img_|photo|foto|portrait|avatar)#i', $u) ? 0 : 1;

            return $score($a) <=> $score($b);
        });

        return array_slice($urls, 0, 8);
    }

    private function absoluteUrl(string $src, string $base): string
    {
        if (str_starts_with($src, 'http')) {
            return $src;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');

        return $origin.'/'.ltrim($src, '/');
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function decode(array $response): array
    {
        $text = data_get($response, 'output_text');
        if (blank($text)) {
            foreach ((array) data_get($response, 'output', []) as $out) {
                foreach ((array) data_get($out, 'content', []) as $c) {
                    if (filled($t = data_get($c, 'text'))) {
                        $text = $t;
                        break 2;
                    }
                }
            }
        }
        $decoded = json_decode((string) $text, true);

        return is_array($decoded) ? $decoded : [];
    }
}
