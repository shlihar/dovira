<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_reviews', function (Blueprint $table): void {
            if (! Schema::hasColumn('profile_reviews', 'external_review_author_avatar_url')) {
                $table->text('external_review_author_avatar_url')->nullable()->after('external_review_author');
            }
        });
    }

    public function down(): void
    {
        Schema::table('profile_reviews', function (Blueprint $table): void {
            if (Schema::hasColumn('profile_reviews', 'external_review_author_avatar_url')) {
                $table->dropColumn('external_review_author_avatar_url');
            }
        });
    }
};
