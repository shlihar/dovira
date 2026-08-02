<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            // Реальний рейтинг з Google (панель знань) — чесніший за підчищені
            // Top20-відгуки. Зберігаємо окремо, щоб показувати як довіру.
            $table->decimal('google_rating', 2, 1)->nullable()->after('rating_avg');
            $table->unsignedInteger('google_reviews_count')->nullable()->after('google_rating');
            $table->timestamp('google_rating_fetched_at')->nullable()->after('google_reviews_count');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['google_rating', 'google_reviews_count', 'google_rating_fetched_at']);
        });
    }
};
