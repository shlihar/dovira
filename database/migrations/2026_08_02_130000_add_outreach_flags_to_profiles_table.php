<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('profiles', 'outreach_flags')) {
                $table->json('outreach_flags')->nullable()->after('internal_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            if (Schema::hasColumn('profiles', 'outreach_flags')) {
                $table->dropColumn('outreach_flags');
            }
        });
    }
};
