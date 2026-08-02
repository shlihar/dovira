<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('profile_reviews')
            ->where(function ($query): void {
                $query->where('verification_type', 'external_top20_import')
                    ->orWhere('verification_type', 'external_google_import')
                    ->orWhere('external_source_type', 'top20');
            })
            ->update([
                'verification_type' => 'external_google_import',
                'external_source_type' => 'google',
                'external_source_url' => null,
                'moderation_note' => 'Імпортовано як зовнішній відгук Google.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
    }
};
