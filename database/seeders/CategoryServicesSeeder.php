<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\CategoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategoryServicesSeeder extends Seeder
{
    public function run(): void
    {
        $servicesByCategory = [
            'Lawyers' => [
                'Допомога по СЗЧ',
                'Виплати за загиблих військових',
                'Оскарження ВЛК',
                'Відстрочка від мобілізації',
                'Сімейне право',
                'Кримінальне право',
                'ДТП',
                'Спадщина',
                'Нерухомість',
                'Бізнес-право',
            ],
            'Dentists' => [
                'Імплантація',
                'Ортодонтія',
                'Брекети',
                'Лікування зубів',
                'Вініри',
                'Дитяча стоматологія',
                'Хірургічна стоматологія',
                'Професійна чистка',
            ],
            'Crypto Exchangers' => [
                'USDT',
                'BTC',
                'ETH',
                'Готівковий обмін',
                'Онлайн-обмін',
                'Офлайн-обмін',
                'Великі суми',
            ],
        ];

        foreach ($servicesByCategory as $categoryName => $services) {
            $category = Category::query()->where('name', $categoryName)->first();
            if (! $category) {
                continue;
            }

            foreach (array_values($services) as $index => $serviceName) {
                CategoryService::query()->updateOrCreate(
                    [
                        'category_id' => $category->id,
                        'slug' => Str::slug($serviceName),
                    ],
                    [
                        'name' => $serviceName,
                        'sort_order' => $index,
                        'is_active' => true,
                        'show_in_catalog' => true,
                    ]
                );
            }
        }
    }
}

