<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Пошуковий індекс профілю: назва, місто, категорії, послуги і ключові слова
 * з його відгуків в одному полі. Потрібен, щоб каталог знаходив виконавця за
 * ПОТРЕБОЮ («вакцинація тварин», «виплати за загиблих»), а не лише за назвою.
 *
 * Чому окрема таблиця, а не пошук по profile_reviews: LIKE по 720 тис. відгуків
 * триває ~6 секунд (заміряно), у живому пошуку це неприйнятно. Тут 34 тис.
 * рядків і FULLTEXT-індекс.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_search_terms', function (Blueprint $table): void {
            $table->unsignedBigInteger('profile_id')->primary();
            $table->mediumText('terms');
            $table->unsignedInteger('reviews_used')->default(0);
            $table->timestamp('updated_at')->nullable();
        });

        // FULLTEXT (InnoDB, MySQL 8): boolean mode дає пошук за префіксом
        // («вакцинац*») і AND по кількох словах запиту.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE profile_search_terms ADD FULLTEXT INDEX profile_search_terms_fulltext (terms)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_search_terms');
    }
};
