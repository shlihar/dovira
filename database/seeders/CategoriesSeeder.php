<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $requestedTree = [
            'Lawyers' => [
                'Military Law',
                'Family Law',
                'Criminal Law',
                'Civil Law',
                'Business Law',
                'Real Estate',
                'Inheritance',
                'Road Accidents',
                'Migration Law',
            ],
            'Notaries' => [],
            'Dentists' => [],
            'Bloggers' => [],
            'Crypto Exchangers' => [],
            'Companies' => [],
        ];

        // Legacy/public categories used on the current public pages.
        $publicTree = [
            'Компанії' => ['Банки', 'Страхування', 'Автодилери', 'Ресторани', 'Аптеки'],
            'Магазини' => ['Техніка', 'Одяг', 'Ювелірні магазини', 'Меблі', 'Онлайн-магазини'],
            'Сервіси' => ['Доставка', 'Освіта', 'Клініки', 'Автосервіси', 'Будівництво', 'Фітнес'],
            'Спеціалісти' => ['Юристи', 'Лікарі', 'Консультанти'],
        ];

        $bulkImportTree = [
            ['name' => 'Юридичні послуги', 'slug' => 'catalog-yurydychni-poslugy', 'children' => [
                ['name' => 'Адвокати', 'slug' => 'advokaty'],
                ['name' => 'Нотаріуси', 'slug' => 'notariusy'],
            ]],
            ['name' => 'МЕДИЦИНА ТА ЗДОРОВ\'Я', 'slug' => 'catalog-medytsyna-ta-zdorov-ya', 'children' => []],
            ['name' => 'БУДІВНИЦТВО ТА РЕМОНТ', 'slug' => 'catalog-budivnytstvo-ta-remont', 'children' => []],
            ['name' => 'ОСВІТА', 'slug' => 'catalog-osvita', 'children' => []],
            ['name' => 'ВЕСІЛЛЯ ТА ПОДІЇ', 'slug' => 'catalog-vesillya-ta-podiyi', 'children' => []],
            ['name' => 'КРАСА ТА ДОГЛЯД', 'slug' => 'catalog-krasa-ta-doglyad', 'children' => []],
            ['name' => 'ТВАРИНИ', 'slug' => 'catalog-tvaryny', 'children' => []],
            ['name' => 'НЕРУХОМІСТЬ', 'slug' => 'catalog-neruhomist', 'children' => []],
            ['name' => 'IT ТА БІЗНЕС-ПОСЛУГИ', 'slug' => 'catalog-it-ta-biznes-poslugy', 'children' => []],
            ['name' => 'БЛОГЕРИ', 'slug' => 'catalog-blogery', 'children' => []],
        ];

        $this->seedTree($requestedTree, 0);
        $this->seedTree($publicTree, 1000);
        $this->seedBulkImportCategories($bulkImportTree, 2000);
    }

    /**
     * @param array<string, array<int, string>> $tree
     */
    private function seedTree(array $tree, int $orderOffset = 0): void
    {
        $order = $orderOffset;

        foreach ($tree as $parentName => $children) {
            $parent = Category::query()->updateOrCreate(
                ['slug' => str($parentName)->slug()->toString()],
                [
                    'name' => $parentName,
                    'description' => null,
                    'status' => 'active',
                    'sort_order' => $order++,
                    'show_in_catalog' => true,
                    'show_in_menu' => true,
                    'is_indexable' => true,
                    'pro_enabled' => true,
                ]
            );

            $childOrder = 0;
            foreach ($children as $childName) {
                Category::query()->updateOrCreate(
                    ['slug' => str($childName)->slug()->toString()],
                    [
                        'parent_id' => $parent->id,
                        'name' => $childName,
                        'description' => null,
                        'status' => 'active',
                        'sort_order' => $childOrder++,
                        'show_in_catalog' => true,
                        'show_in_menu' => true,
                        'is_indexable' => true,
                        'pro_enabled' => true,
                    ]
                );
            }
        }
    }

    /**
     * @param  array<int, array{name:string,slug:string,children:array<int, array{name:string,slug:string}>}>  $categories
     */
    private function seedBulkImportCategories(array $categories, int $orderOffset = 0): void
    {
        $order = $orderOffset;

        foreach ($categories as $item) {
            $parent = Category::query()->updateOrCreate(
                ['slug' => $item['slug']],
                [
                    'parent_id' => null,
                    'name' => $item['name'],
                    'description' => null,
                    'status' => 'active',
                    'sort_order' => $order++,
                    'show_in_catalog' => true,
                    'show_in_menu' => true,
                    'is_indexable' => true,
                    'pro_enabled' => true,
                ]
            );

            foreach ($item['children'] as $index => $child) {
                Category::query()->updateOrCreate(
                    ['slug' => $child['slug']],
                    [
                        'parent_id' => $parent->id,
                        'name' => $child['name'],
                        'description' => null,
                        'status' => 'active',
                        'sort_order' => $index,
                        'show_in_catalog' => true,
                        'show_in_menu' => true,
                        'is_indexable' => true,
                        'pro_enabled' => true,
                    ]
                );
            }
        }
    }
}
