<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Profile;
use App\Support\CategoryHierarchy;
use App\Support\RegionCityDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogDataAuditor
{
    /**
     * Ручні маркери потрібні саме для strong mismatch: коли текст профілю явно
     * вказує на іншу рубрику. Брендові slug без категорій не штрафуємо.
     *
     * @var array<string, array<int, string>>
     */
    private const CATEGORY_KEYWORDS = [
        'advokaty' => ['advokat', 'advokats', 'urist', 'iurist', 'yurist', 'jurist'],
        'notariusy' => ['notarius', 'notarial'],
        'kosmetolog' => ['kosmetolog', 'dermatolog', 'epiliac', 'lazer'],
        'kosmetologicni-kliniki' => ['kosmetologicn', 'esteticn'],
        'plasticni-xirurgi' => ['plasticn', 'xirurg', 'khirurg'],
        'privatni-kliniki' => ['klinika', 'klinic', 'medcentr', 'medicn', 'urologicn'],
        'stomatologiyi' => ['stomatolog', 'stomatologicn', 'dent', 'zub', 'ortodont'],
        'veterinari-ta-veterinarni-kliniki' => ['veterinar', 'veterynar', 'vetclinic', 'zoo'],
        'agenstva-neruxomosti' => ['neruxom', 'rieltor', 'realtor'],
        'budivelni-kompaniyi' => ['budivel', 'remont'],
        'budivelni-magazini' => ['budmagazin', 'budivelnii-magazin'],
        'vstanovlennia-vikon' => ['vikna', 'vikon'],
        'zabudovniki' => ['zabudovnik', 'developer'],
        'avtoskoli' => ['avtoskola', 'driving'],
        'movni-shkoly' => ['movna-skola', 'english', 'angliisk'],
        'audit-finansovoyi-zvitnosti' => ['audit', 'auditor'],
        'ximcistki' => ['ximcist', 'khimchist'],
        'restorani' => ['restoran', 'restaurant'],
    ];

    private const GENERIC_CATEGORY_TOKENS = [
        'catalog',
        'poslugi',
        'poslugy',
        'kompaniyi',
        'kompanii',
        'ta',
    ];

    /** @var Collection<int, Category>|null */
    private ?Collection $categories = null;

    /** @var array<int, array<int, string>> */
    private array $keywordsByCategoryId = [];

    /**
     * @return array{checked:int, fixed:int, counts:array<string, int>, issues:array<int, array<string, mixed>>}
     */
    public function audit(?int $profileId = null, int $limit = 0, bool $onlyVisible = false, bool $fix = false): array
    {
        $result = [
            'checked' => 0,
            'fixed' => 0,
            'counts' => [],
            'issues' => [],
        ];

        $query = Profile::query()
            ->with(['categories:id,parent_id,name,slug'])
            ->when($profileId !== null, fn (Builder $q) => $q->whereKey($profileId))
            ->when($onlyVisible, fn (Builder $q) => $q
                ->where('status', 'active')
                ->where('is_published', true)
                ->where('show_in_catalog', true))
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $query->chunkById(500, function (Collection $profiles) use (&$result, $fix): void {
            foreach ($profiles as $profile) {
                $result['checked']++;

                $issues = $this->issuesFor($profile);
                if ($issues === []) {
                    continue;
                }

                if ($fix && $this->fixProfile($profile, $issues)) {
                    $result['fixed']++;
                    $profile->refresh()->load('categories:id,parent_id,name,slug');
                    $issues = $this->issuesFor($profile);
                }

                foreach ($issues as $issue) {
                    $type = (string) $issue['type'];
                    $result['counts'][$type] = ($result['counts'][$type] ?? 0) + 1;
                    $result['issues'][] = [
                        'profile_id' => (int) $profile->id,
                        'name' => (string) $profile->name,
                        'slug' => (string) $profile->slug,
                        'city' => $profile->city,
                        ...$issue,
                    ];
                }
            }
        });

        ksort($result['counts']);

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function issuesFor(Profile $profile): array
    {
        $categories = $profile->categories;
        $primaryCategories = $categories->filter(fn (Category $category): bool => (bool) $category->pivot->is_primary)->values();
        $leafCategories = $categories->filter(fn (Category $category): bool => $category->parent_id !== null)->values();
        $suggested = $this->suggestedCategory($profile);
        $issues = [];

        if ($primaryCategories->count() === 0) {
            $issues[] = [
                'type' => 'missing_primary_category',
                'severity' => 'high',
                'message' => 'Профіль має категорії, але не має primary-категорії.',
                'suggested_category' => $this->categoryPayload($suggested),
            ];
        } elseif ($primaryCategories->count() > 1) {
            $issues[] = [
                'type' => 'multiple_primary_categories',
                'severity' => 'high',
                'message' => 'Профіль має більше однієї primary-категорії.',
                'primary_categories' => $primaryCategories->map(fn (Category $category): array => $category->only(['id', 'name', 'slug']))->all(),
                'suggested_category' => $this->categoryPayload($suggested),
            ];
        }

        if ($leafCategories->count() > 1) {
            $issues[] = [
                'type' => 'multiple_leaf_categories',
                'severity' => 'medium',
                'message' => 'Профіль прив’язаний до кількох підкатегорій; у рейтингах має працювати одна primary-рубрика.',
                'leaf_categories' => $leafCategories->map(fn (Category $category): array => $category->only(['id', 'name', 'slug']))->all(),
                'suggested_category' => $this->categoryPayload($suggested),
            ];
        }

        $primary = $primaryCategories->first();
        if ($primary && $suggested && (int) $primary->id !== (int) $suggested->id && ! $this->categoryMatchesProfile($profile, $primary)) {
            $issues[] = [
                'type' => 'category_slug_mismatch',
                'severity' => 'high',
                'message' => 'Назва/slug профілю явно відповідають іншій категорії, ніж поточна primary.',
                'primary_category' => $primary->only(['id', 'name', 'slug']),
                'suggested_category' => $this->categoryPayload($suggested),
            ];
        }

        $canonicalCity = RegionCityDirectory::canonicalRegisteredCity((string) ($profile->city ?? ''));
        if ($canonicalCity !== null && (string) $profile->city !== $canonicalCity) {
            $issues[] = [
                'type' => 'city_alias_needs_normalization',
                'severity' => 'low',
                'message' => 'Місто є в довіднику як alias і має бути приведене до канонічної назви.',
                'suggested_city' => $canonicalCity,
            ];
        } elseif (filled($profile->city) && ! RegionCityDirectory::isRegisteredCity((string) $profile->city)) {
            $issues[] = [
                'type' => 'city_not_in_registry',
                'severity' => 'medium',
                'message' => 'Місто не знайдено в довіднику міст.',
            ];
        }

        return $issues;
    }

    /**
     * @param  array<int, array<string, mixed>>  $issues
     */
    public function fixProfile(Profile $profile, array $issues): bool
    {
        $changed = false;
        $suggested = $this->suggestedCategoryForFix($profile);

        if ($suggested !== null && $this->hasCategoryIssue($issues)) {
            CategoryHierarchy::syncProfileCategories(
                $profile,
                $this->rootCategoryId($suggested),
                $suggested->parent_id ? (int) $suggested->id : null
            );
            $changed = true;
        }

        $canonicalCity = RegionCityDirectory::canonicalRegisteredCity((string) ($profile->city ?? ''));
        if ($canonicalCity !== null && (string) $profile->city !== $canonicalCity) {
            $profile->city = $canonicalCity;
            $profile->save();
            $changed = true;
        }

        if ($changed) {
            DB::table('profiles')->where('id', $profile->id)->update(['updated_at' => now()]);
        }

        return $changed;
    }

    public function suggestedCategory(Profile $profile): ?Category
    {
        return $this->highestScoringCategory(
            fn (Category $category): int => $this->categoryScore($profile, $category),
            $this->categories()
        );
    }

    private function suggestedCategoryForFix(Profile $profile): ?Category
    {
        return $this->highestScoringCategory(
            fn (Category $category): int => $this->categoryScoreForText(
                $this->normalize(implode(' ', [
                    $profile->name,
                    $profile->short_description,
                ])),
                $category
            ),
            $this->categories()->filter(fn (Category $category): bool => $category->parent_id !== null)->values()
        );
    }

    /**
     * @param  callable(Category):int  $scoreResolver
     * @param  Collection<int, Category>  $categories
     */
    private function highestScoringCategory(callable $scoreResolver, Collection $categories): ?Category
    {
        $scores = $categories
            ->mapWithKeys(fn (Category $category): array => [(int) $category->id => $scoreResolver($category)])
            ->filter(fn (int $score): bool => $score > 0)
            ->sortDesc();

        if ($scores->isEmpty()) {
            return null;
        }

        $topScore = (int) $scores->first();
        $topIds = $scores->filter(fn (int $score): bool => $score === $topScore)->keys();

        if ($topIds->count() !== 1) {
            return null;
        }

        return $this->categories()->firstWhere('id', (int) $topIds->first());
    }

    private function categoryMatchesProfile(Profile $profile, Category $category): bool
    {
        return $this->categoryScore($profile, $category) > 0;
    }

    private function categoryScore(Profile $profile, Category $category): int
    {
        return $this->categoryScoreForText($this->normalizedProfileText($profile), $category);
    }

    private function categoryScoreForText(string $text, Category $category): int
    {
        return collect($this->categoryKeywords($category))
            ->sum(fn (string $keyword): int => str_contains($text, $keyword) ? 1 : 0);
    }

    /**
     * @return array<int, string>
     */
    private function categoryKeywords(Category $category): array
    {
        $categoryId = (int) $category->id;

        return $this->keywordsByCategoryId[$categoryId] ??= collect(self::CATEGORY_KEYWORDS[$category->slug] ?? $this->keywordsFromCategory($category))
            ->map(fn (string $keyword): string => $this->normalize($keyword))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function keywordsFromCategory(Category $category): array
    {
        return collect([$category->name, $category->slug])
            ->flatMap(fn (string $value): array => preg_split('/[\s\-]+/u', $this->normalize($value)) ?: [])
            ->filter(fn (string $token): bool => mb_strlen($token) >= 5)
            ->reject(fn (string $token): bool => in_array($token, self::GENERIC_CATEGORY_TOKENS, true))
            ->values()
            ->all();
    }

    private function normalizedProfileText(Profile $profile): string
    {
        return $this->normalize(implode(' ', [
            $profile->name,
            $profile->slug,
            $profile->short_description,
        ]));
    }

    private function normalize(string $value): string
    {
        $value = Str::ascii(mb_strtolower($value, 'UTF-8'));
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * @return Collection<int, Category>
     */
    private function categories(): Collection
    {
        return $this->categories ??= Category::query()
            ->where('is_active', true)
            ->orderByRaw('case when parent_id is null then 1 else 0 end')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'slug']);
    }

    private function rootCategoryId(Category $category): int
    {
        return (int) ($category->parent_id ?: $category->id);
    }

    /**
     * @return array{id:int,name:string,slug:string}|null
     */
    private function categoryPayload(?Category $category): ?array
    {
        if ($category === null) {
            return null;
        }

        return [
            'id' => (int) $category->id,
            'name' => (string) $category->name,
            'slug' => (string) $category->slug,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $issues
     */
    private function hasCategoryIssue(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (in_array($issue['type'] ?? null, [
                'missing_primary_category',
                'multiple_primary_categories',
                'multiple_leaf_categories',
                'category_slug_mismatch',
            ], true)) {
                return true;
            }
        }

        return false;
    }
}
