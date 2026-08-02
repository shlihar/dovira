<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_reviews', function (Blueprint $table): void {
            $table->index(
                ['profile_id', 'external_source_type', 'external_review_date', 'published_at'],
                'profile_reviews_top20_source_prune_idx'
            );
            $table->index(
                ['profile_id', 'verification_type', 'external_review_date', 'published_at'],
                'profile_reviews_top20_verification_prune_idx'
            );
            $table->index(
                ['profile_id', 'rating', 'external_review_author', 'external_review_date'],
                'profile_reviews_top20_duplicate_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('profile_reviews', function (Blueprint $table): void {
            $table->dropIndex('profile_reviews_top20_duplicate_idx');
            $table->dropIndex('profile_reviews_top20_verification_prune_idx');
            $table->dropIndex('profile_reviews_top20_source_prune_idx');
        });
    }
};
