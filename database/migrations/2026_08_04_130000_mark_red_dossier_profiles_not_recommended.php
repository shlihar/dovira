<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('profiles')
            ->where('dossier_verdict', 'red')
            ->update([
                'dovira_recommendation_status' => 'not_recommend',
                'dovira_recommends' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // No-op: recommendation status can be changed manually after migration.
    }
};
