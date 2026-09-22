<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Середня по категорії (p у байєсівській формулі) та інша статистика.
 * Рахується першою в конвеєрі — від неї залежить згладжування рейтингів.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->unique()->constrained('categories')->cascadeOnDelete();
            $table->float('mean_rating')->default(4.5); // p у формулі
            $table->integer('median_reviews')->default(0);
            $table->integer('profiles_count')->default(0);
            $table->integer('reviews_count')->default(0);
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_stats');
    }
};
