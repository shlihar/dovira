<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Місячні зрізи рейтингів «ТОП-10 {категорія} {місто}».
 *
 * Сторінка рендерить заморожений зріз, а не живий запит: інакше склад топу
 * пливе щодня, Google бачить нестабільний документ, а стаття не може чесно
 * сказати «станом на вересень 2026». Зріз також дає різницю між місяцями —
 * з неї пишеться абзац «що змінилось».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('top_rating_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('period', 7);                 // 2026-09
            $table->string('category_slug', 160);
            $table->string('city_slug', 160)->nullable(); // null = Україна
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->decimal('index_score', 5, 1);         // 0..100
            $table->json('components');                   // розклад по вимірах
            $table->decimal('rating', 3, 2);
            $table->unsignedInteger('reviews_count');
            $table->unsignedInteger('reviews_last_90d')->default(0);
            $table->timestamps();

            $table->unique(['period', 'category_slug', 'city_slug', 'position'], 'top_snap_slot_unique');
            $table->index(['period', 'category_slug', 'city_slug'], 'top_snap_page_idx');
            $table->index(['profile_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('top_rating_snapshots');
    }
};
