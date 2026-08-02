<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profiles') || ! Schema::hasTable('profile_data_sources')) {
            return;
        }

        DB::table('profiles')
            ->whereNotNull('logo_url')
            ->whereIn('id', function ($query): void {
                $query->select('profile_id')
                    ->from('profile_data_sources')
                    ->whereNotNull('profile_id')
                    ->whereIn('source_type', ['top20_import', 'top20'])
                    ->whereNotExists(function ($nested): void {
                        $nested->selectRaw('1')
                            ->from('profile_data_sources as s2')
                            ->whereColumn('s2.profile_id', 'profile_data_sources.profile_id')
                            ->whereIn('s2.source_type', [
                                'official_website_logo',
                                'google_maps_photo',
                                'ai_google_maps',
                                'official_website',
                                'ai_official_website',
                            ]);
                    })
                    ->distinct();
            })
            ->update([
                'logo_url' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
    }
};
