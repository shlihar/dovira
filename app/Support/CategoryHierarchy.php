<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Profile;
use Illuminate\Support\Collection;

class CategoryHierarchy
{
    public static function rootOptions(): array
    {
        return Category::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function childOptions(?int $parentId): array
    {
        if (! $parentId) {
            return [];
        }

        return Category::query()
            ->where('parent_id', $parentId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array{category_id:?int,subcategory_id:?int}
     */
    public static function selectionFromProfile(Profile $profile): array
    {
        $primary = $profile->categories()->wherePivot('is_primary', true)->first();

        if ($primary && $primary->parent_id) {
            return [
                'category_id' => (int) $primary->parent_id,
                'subcategory_id' => (int) $primary->id,
            ];
        }

        return [
            'category_id' => $primary ? (int) $primary->id : null,
            'subcategory_id' => null,
        ];
    }

    public static function resolveRootCategoryId(?int $categoryId): ?int
    {
        if (! $categoryId) {
            return null;
        }

        $category = Category::query()->find($categoryId);
        if (! $category) {
            return null;
        }

        return $category->parent_id ? (int) $category->parent_id : (int) $category->id;
    }

    public static function resolveValidSubcategoryId(?int $categoryId, ?int $subcategoryId): ?int
    {
        if (! $categoryId || ! $subcategoryId) {
            return null;
        }

        return Category::query()
            ->where('parent_id', $categoryId)
            ->whereKey($subcategoryId)
            ->value('id');
    }

    /**
     * @return array<int, int>
     */
    public static function descendantIds(Category|int|null $category): array
    {
        $categoryId = $category instanceof Category ? (int) $category->id : (int) $category;
        if ($categoryId <= 0) {
            return [];
        }

        $ids = [$categoryId];
        $queue = [$categoryId];

        while ($queue !== []) {
            $current = array_shift($queue);
            $children = Category::query()
                ->where('parent_id', $current)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            foreach ($children as $childId) {
                if (in_array($childId, $ids, true)) {
                    continue;
                }

                $ids[] = $childId;
                $queue[] = $childId;
            }
        }

        return $ids;
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, int>
     */
    public static function categoryIdsFromNames(array $names): array
    {
        $names = collect($names)
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique(fn ($name) => mb_strtolower($name))
            ->values();

        if ($names->isEmpty()) {
            return [];
        }

        $lowerNames = $names->map(fn ($name) => mb_strtolower($name));
        $slugs = $names->map(fn ($name) => str($name)->slug()->toString());

        // Порівнюємо регістр у PHP, а не через SQL lower(): у SQLite (тести)
        // lower() не вміє кирилицю, тож lower(name) = ? там завжди хибно, хоча
        // на MySQL (прод) це працює. Категорій мало, тож вибірка всіх дешева.
        $matched = Category::query()
            ->get(['id', 'name', 'slug'])
            ->filter(fn (Category $category) => $lowerNames->contains(mb_strtolower($category->name))
                || $slugs->contains($category->slug));

        $ids = [];
        foreach ($matched as $category) {
            foreach (self::descendantIds((int) $category->id) as $id) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    public static function syncProfileCategories(Profile $profile, ?int $categoryId, ?int $subcategoryId = null): void
    {
        if (! $categoryId) {
            $profile->categories()->sync([]);

            return;
        }

        $rootId = self::resolveRootCategoryId($categoryId);
        if (! $rootId) {
            $profile->categories()->sync([]);

            return;
        }

        $childId = self::resolveValidSubcategoryId($rootId, $subcategoryId);
        $primaryId = $childId ?: $rootId;
        $sync = [
            $rootId => ['is_primary' => $primaryId === $rootId],
        ];

        if ($childId) {
            $sync[$childId] = ['is_primary' => true];
        }

        $profile->categories()->sync($sync);
    }

    public static function primaryCategory(Profile $profile): ?Category
    {
        return $profile->categories()->wherePivot('is_primary', true)->first();
    }
}
