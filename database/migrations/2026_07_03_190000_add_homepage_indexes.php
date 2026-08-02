<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->index(
                ['status', 'is_published', 'show_in_catalog', 'dovira_recommendation_status', 'reviews_count'],
                'profiles_home_recommended_idx'
            );
            $table->index(
                ['status', 'is_published', 'show_in_catalog', 'reviews_count', 'rating_avg'],
                'profiles_home_best_idx'
            );
            $table->index(
                ['status', 'is_published', 'show_in_catalog', 'popularity_score'],
                'profiles_home_popular_idx'
            );
        });

        Schema::table('profile_reviews', function (Blueprint $table): void {
            $table->index(['status', 'published_at', 'id'], 'profile_reviews_home_latest_idx');
        });
    }

    public function down(): void
    {
        Schema::table('profile_reviews', function (Blueprint $table): void {
            $table->dropIndex('profile_reviews_home_latest_idx');
        });

        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropIndex('profiles_home_popular_idx');
            $table->dropIndex('profiles_home_best_idx');
            $table->dropIndex('profiles_home_recommended_idx');
        });
    }
};
