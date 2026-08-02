<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_reviews', function (Blueprint $table): void {
            if (! Schema::hasColumn('profile_reviews', 'external_review_hash')) {
                $table->string('external_review_hash', 40)->nullable()->after('external_source_url');
                $table->index(['profile_id', 'external_review_hash']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('profile_reviews', function (Blueprint $table): void {
            if (Schema::hasColumn('profile_reviews', 'external_review_hash')) {
                $table->dropIndex(['profile_id', 'external_review_hash']);
                $table->dropColumn('external_review_hash');
            }
        });
    }
};
