<?php

namespace Database\Seeders;

use App\Models\Lawyer;
use App\Models\Region;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class LawyersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $kyiv = Region::where('slug', 'kyivska-oblast')->first() ?? Region::first();

        Lawyer::create([
            'full_name' => 'Аніщенко Олена Георгіївна',
            'certificate_number' => '123456',
            'certificate_issued_at' => '2015-04-12',
            'certificate_issuer' => 'КДКА',
            'decision_number' => '78/2015',
            'decision_at' => '2015-04-10',
            'email' => 'olena@example.com',
            'photo_url' => 'https://example.com/photo.jpg',
            'is_suspended' => false,
            'notes' => 'Демонстраційний запис для тесту.',
            'region_id' => $kyiv->id,
        ]);
    }
}
