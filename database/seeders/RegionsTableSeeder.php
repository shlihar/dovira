<?php

namespace Database\Seeders;

use App\Models\Region;
use Illuminate\Database\Seeder;

class RegionsTableSeeder extends Seeder
{
    public function run(): void
    {
        $regions = [
            'Вінницька область',
            'Волинська область',
            'Дніпропетровська область',
            'Донецька область',
            'Житомирська область',
            'Закарпатська область',
            'Запорізька область',
            'Івано-Франківська область',
            'Київська область',
            'Кіровоградська область',
            'Луганська область',
            'Львівська область',
            'Миколаївська область',
            'Одеська область',
            'Полтавська область',
            'Рівненська область',
            'Сумська область',
            'Тернопільська область',
            'Харківська область',
            'Херсонська область',
            'Хмельницька область',
            'Черкаська область',
            'Чернівецька область',
            'Чернігівська область',
            'м. Київ',
        ];

        foreach ($regions as $region) {
            Region::firstOrCreate(
                ['slug' => $this->slugify($region)],
                ['name' => $region]
            );
        }
    }

    private function slugify(string $name): string
    {
        return str($name)->slug('-')->toString();
    }
}
