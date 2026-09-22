<?php

namespace App\Console\Commands;

use App\Models\Profile;
use Illuminate\Console\Command;

/**
 * Прибирає сліди top20.ua з текстів профілів: конкурентний бренд «ТОП 20»
 * потрапив у seo_description / short_description / description під час імпорту.
 *
 * - seo_description, short_description — це фактично шаблон top20.ua цілком,
 *   тож обнуляємо (сайт згенерує власний чистий опис із ProfileSeo).
 * - description — корисний абзац із отруєним реченням про «ТОП 20»: вирізаємо
 *   лише речення-порушники, решту лишаємо; якщо нічого не лишилось — null.
 */
class StripTop20Branding extends Command
{
    protected $signature = 'dovira:strip-top20
        {--dry-run : Порахувати без запису}';

    protected $description = 'Вичищає згадки бренду «ТОП 20» (top20.ua) з описів профілів.';

    /**
     * Маркери top20.ua: сам бренд (у т.ч. NBSP/обрізаний), фраза-атрибуція
     * «зібрані на довідці» та емодзі-шаблон їхнього meta (💬📷☎).
     */
    private const MARKERS = [
        '/(топ|top)[\s\x{00A0}\x{2011}\x{2013}\x{2014}\-]?20/iu',
        '/зібран[оаі]?\s+на\s+довідц/iu',
        '/[\x{1F4AC}\x{1F4F7}\x{260E}]/u',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $counters = ['seo' => 0, 'short' => 0, 'desc_stripped' => 0, 'desc_nulled' => 0];

        Profile::query()
            ->where(function ($q): void {
                foreach (['seo_description', 'short_description', 'description'] as $col) {
                    foreach (['%ТОП%20%', '%top20%', '%top 20%', '%зібран% на довідц%', '%💬%', '%📷%', '%☎%'] as $like) {
                        $q->orWhere($col, 'like', $like);
                    }
                }
            })
            ->select(['id', 'seo_description', 'short_description', 'description'])
            ->chunkById(500, function ($profiles) use (&$counters, $dryRun): void {
                foreach ($profiles as $profile) {
                    $dirty = [];

                    // seo_description / short_description — шаблон top20 цілком → null.
                    foreach (['seo_description' => 'seo', 'short_description' => 'short'] as $col => $key) {
                        if ($this->hasMarker((string) $profile->{$col})) {
                            $dirty[$col] = null;
                            $counters[$key]++;
                        }
                    }

                    // description — вирізаємо лише речення з маркером.
                    if ($this->hasMarker((string) $profile->description)) {
                        $cleaned = $this->stripMarkerSentences((string) $profile->description);
                        $dirty['description'] = $cleaned === '' ? null : $cleaned;
                        $counters[$cleaned === '' ? 'desc_nulled' : 'desc_stripped']++;
                    }

                    if ($dirty !== [] && ! $dryRun) {
                        $profile->forceFill($dirty)->saveQuietly();
                    }
                }
            });

        $this->info($dryRun ? 'DRY-RUN, нічого не записано.' : 'Готово.');
        $this->table(['Поле', 'Оброблено'], [
            ['seo_description → null', $counters['seo']],
            ['short_description → null', $counters['short']],
            ['description: вирізано речення', $counters['desc_stripped']],
            ['description → null (порожній)', $counters['desc_nulled']],
        ]);

        return self::SUCCESS;
    }

    private function hasMarker(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        foreach (self::MARKERS as $marker) {
            if (preg_match($marker, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Розбиваємо на речення й лишаємо тільки ті, де немає маркера конкурента.
     */
    private function stripMarkerSentences(string $text): string
    {
        $sentences = preg_split('/(?<=[.!?…])\s+/u', trim($text)) ?: [];

        $kept = array_filter(
            $sentences,
            fn (string $sentence): bool => trim($sentence) !== '' && ! $this->hasMarker($sentence)
        );

        return trim(implode(' ', $kept));
    }
}
