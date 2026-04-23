<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lawyers', function (Blueprint $table) {
            if (! Schema::hasColumn('lawyers', 'source_hash')) {
                $table->string('source_hash', 40)->nullable()->after('id');
            }
        });

        Schema::table('lawyers', function (Blueprint $table) {
            try {
                $table->dropUnique(['certificate_number']);
            } catch (\Throwable) {
                // Ignore if the unique index does not exist (driver differences / already migrated).
            }
        });

        Schema::table('lawyers', function (Blueprint $table) {
            $table->unique('source_hash');
            $table->index('certificate_number');
        });
    }

    public function down(): void
    {
        Schema::table('lawyers', function (Blueprint $table) {
            $table->dropUnique(['source_hash']);
            $table->dropIndex(['certificate_number']);
        });

        Schema::table('lawyers', function (Blueprint $table) {
            $table->unique('certificate_number');
        });

        Schema::table('lawyers', function (Blueprint $table) {
            if (Schema::hasColumn('lawyers', 'source_hash')) {
                $table->dropColumn('source_hash');
            }
        });
    }
};

