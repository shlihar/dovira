<?php

namespace App\Services\AiEnrichment\Providers;

use App\Models\AiEnrichmentBatch;
use App\Models\Category;
use App\Services\AiEnrichment\Contracts\ProfileEnrichmentProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class LocalHeuristicProfileEnrichmentProvider implements ProfileEnrichmentProvider
{
    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function enrich(AiEnrichmentBatch $batch, array $item, ?Category $category, ?string $city): array
    {
        $name = trim((string) $item['name']);
        $categoryName = $category?->name;
        $services = $this->inferServices($categoryName, $name);
        $description = null;

        if ((bool) Arr::get($batch->options ?? [], 'generate_description', true)) {
            $description = $this->buildDescription($name, $categoryName, $city, $services);
        }

        return [
            'name' => $name,
            'category' => $categoryName,
            'city' => $city,
            'country' => $batch->default_country ?: 'Україна',
            'phone' => $item['phone'] ?? null,
            'website' => $item['website'] ?? null,
            'email' => $item['email'] ?? null,
            'address' => $item['address'] ?? null,
            'source_url' => $item['source_url'] ?? null,
            'services' => $services,
            'short_description' => $description,
            'description' => $description,
            'seo_title' => (bool) Arr::get($batch->options ?? [], 'generate_seo', true)
                ? $name . ($city ? ' ' . $city : '') . ' — відгуки, контакти, інформація | DOVIRA'
                : null,
            'seo_description' => (bool) Arr::get($batch->options ?? [], 'generate_seo', true)
                ? 'Інформація про ' . $name . ($city ? ' у місті ' . $city : '') . ': контакти, джерела, відгуки та репутація на DOVIRA.'
                : null,
            'confidence_notes' => [
                'Профіль створено як чернетку.',
                'Факти без джерел не публікуються автоматично.',
            ],
            'provider' => static::class,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function inferServices(?string $categoryName, string $name): array
    {
        $haystack = Str::lower($name . ' ' . $categoryName);

        return match ($categoryName) {
            'Адвокати' => array_values(array_filter([
                Str::contains($haystack, ['військ', 'сзч', 'влк', 'мобілізац']) ? 'Військове право' : null,
                Str::contains($haystack, ['сім', 'розлуч']) ? 'Сімейне право' : null,
                Str::contains($haystack, ['кримін']) ? 'Кримінальне право' : null,
                Str::contains($haystack, ['дтп']) ? 'ДТП' : null,
            ])) ?: ['Правова допомога'],
            'Стоматологи' => array_values(array_filter([
                Str::contains($haystack, ['ортод', 'брекет']) ? 'Ортодонтія' : null,
                Str::contains($haystack, ['імплант']) ? 'Імплантація' : null,
                Str::contains($haystack, ['дит']) ? 'Дитяча стоматологія' : null,
            ])) ?: ['Лікування зубів'],
            'Нотаріуси' => ['Договори', 'Спадщина', 'Нерухомість'],
            'Криптообмінники' => ['Онлайн-обмін', 'USDT', 'BTC'],
            'Блогери' => ['Контент', 'Соцмережі'],
            default => [],
        };
    }

    /**
     * @param  array<int, string>  $services
     */
    private function buildDescription(string $name, ?string $categoryName, ?string $city, array $services): string
    {
        $location = $city ? ' у місті ' . $city : '';
        $serviceList = $services ? implode(', ', array_slice($services, 0, 4)) : 'профільні послуги';

        return match ($categoryName) {
            'Адвокати' => "{$name}{$location} надає юридичну допомогу за напрямами: {$serviceList}. Спеціаліст допомагає оцінити ситуацію, підготувати документи, сформувати правову позицію та зрозуміти можливі ризики. Профіль створено на основі імпорту й потребує ручної перевірки джерел перед публікацією.",
            'Стоматологи' => "{$name}{$location} надає стоматологічні послуги за напрямами: {$serviceList}. Клініка допомагає оцінити стан здоров’я порожнини рота, підібрати план лікування та отримати супровід на етапах діагностики, лікування й профілактики. Профіль створено на основі імпорту й потребує ручної перевірки джерел перед публікацією.",
            'Нотаріуси' => "{$name}{$location} працює з нотаріальними послугами за напрямами: {$serviceList}. Профіль може бути корисним для перевірки контактів, базової інформації та подальшого порівняння відгуків користувачів. Дані потребують ручної перевірки перед публікацією.",
            'Криптообмінники' => "{$name}{$location} пов’язаний з обмінними послугами за напрямами: {$serviceList}. Профіль допомагає зібрати контакти, джерела, зовнішні згадки та підготувати сторінку для подальшої модерації. Дані потребують ручної перевірки перед публікацією.",
            default => "{$name}{$location} — профіль платформи DOVIRA у категорії " . Str::lower((string) ($categoryName ?: 'профілі')) . ". Сторінка допомагає зібрати контакти, опис, напрями діяльності, джерела та майбутні відгуки користувачів. Дані потребують ручної перевірки перед публікацією.",
        };
    }
}
