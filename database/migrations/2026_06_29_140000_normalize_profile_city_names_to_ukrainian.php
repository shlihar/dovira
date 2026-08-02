<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $aliases = [
            'Винница' => 'Вінниця',
            'винница' => 'Вінниця',
            'Киев' => 'Київ',
            'киев' => 'Київ',
        ];

        foreach ($aliases as $alias => $canonical) {
            DB::table('profiles')
                ->where('city', trim((string) $alias))
                ->update(['city' => $canonical]);
        }
    }

    public function down(): void
    {
        // Irreversible data normalization.
    }
};
