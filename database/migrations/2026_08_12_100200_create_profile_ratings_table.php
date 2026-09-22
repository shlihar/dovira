<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Денормалізований рейтинг + популярність + стан бейджа, по одному рядку на
 * профіль. Оновлюється батчем; сторінки ЧИТАЮТЬ звідси, не рахують на льоту.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->unique()->constrained('profiles')->cascadeOnDelete();

            // --- Рейтинг (якість) ---
            $table->float('rating')->default(0)->index();     // фінал 1..5
            $table->float('raw_average')->default(0);         // проста середня
            $table->float('weighted_average')->default(0);    // середня з вагами
            $table->float('bayesian_rating')->default(0);     // після згладжування

            // --- Вхідні дані розрахунку ---
            $table->integer('reviews_total')->default(0);
            $table->integer('reviews_counted')->default(0);   // потрапили в розрахунок
            $table->integer('reviews_own')->default(0);       // platform + confirmed
            $table->integer('reviews_confirmed')->default(0); // тільки confirmed
            $table->integer('reviews_imported')->default(0);
            $table->integer('reviews_last_90d')->default(0);
            $table->float('effective_weight_sum')->default(0); // Σ ваг

            // --- Розподіл оцінок ---
            $table->integer('dist_1')->default(0);
            $table->integer('dist_2')->default(0);
            $table->integer('dist_3')->default(0);
            $table->integer('dist_4')->default(0);
            $table->integer('dist_5')->default(0);
            $table->float('distribution_entropy')->default(0);

            // --- Позиція в каталозі ---
            $table->float('popularity_score')->default(0)->index();
            $table->float('completeness_factor')->default(1.0);
            $table->float('engagement_factor')->default(1.0);
            $table->float('responsiveness_factor')->default(1.0);

            // --- Бейдж ---
            $table->boolean('is_recommended')->default(false)->index();
            $table->timestamp('recommended_since')->nullable();
            $table->timestamp('recommended_lost_at')->nullable();
            $table->boolean('badge_at_risk')->default(false);
            $table->timestamp('badge_at_risk_since')->nullable();
            $table->json('badge_failed_criteria')->nullable(); // непройдені умови

            // --- Службове ---
            $table->boolean('is_rating_reliable')->default(false);
            $table->string('calculation_version', 16)->default('1.0');
            $table->json('calculation_breakdown')->nullable(); // повна розкладка
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->index(['rating', 'reviews_counted']);
            $table->index(['is_recommended', 'rating']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_ratings');
    }
};
