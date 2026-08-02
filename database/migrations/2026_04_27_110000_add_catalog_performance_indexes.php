<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->index(['status', 'reviews_count', 'rating_avg'], 'profiles_status_reviews_rating_idx');
            $table->index(['status', 'city'], 'profiles_status_city_idx');
        });

        Schema::table('profile_category', function (Blueprint $table) {
            $table->index(['profile_id', 'is_primary'], 'profile_category_profile_primary_idx');
        });

        Schema::table('profile_reviews', function (Blueprint $table) {
            $table->index(['profile_id', 'status', 'rating'], 'profile_reviews_profile_status_rating_idx');
        });
    }

    public function down(): void
    {
        Schema::table('profile_reviews', function (Blueprint $table) {
            $table->dropIndex('profile_reviews_profile_status_rating_idx');
        });

        Schema::table('profile_category', function (Blueprint $table) {
            $table->dropIndex('profile_category_profile_primary_idx');
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropIndex('profiles_status_city_idx');
            $table->dropIndex('profiles_status_reviews_rating_idx');
        });
    }
};
