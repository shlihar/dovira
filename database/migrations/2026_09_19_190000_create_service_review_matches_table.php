<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Матеріалізовані збіги «відгук × послуга» для сервісних посадкових
 * (config/service_landings.php). Пошук LIKE по 700 тис. відгуків не може
 * виконуватись у веб-запиті — таблицю перебудовує dovira:service-landings:rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_review_matches', function (Blueprint $table): void {
            $table->id();
            $table->string('category_slug', 64);
            $table->string('service_slug', 64);
            $table->unsignedBigInteger('review_id');
            $table->unsignedBigInteger('profile_id');
            // Канонічне місто профілю (ключ і назва); null — місто поза довідником.
            $table->string('city_key', 96)->nullable();
            $table->string('city_name', 96)->nullable();
            $table->unsignedTinyInteger('rating')->default(0);
            $table->date('reviewed_at')->nullable();
            $table->unsignedSmallInteger('body_length')->default(0);

            $table->unique(['category_slug', 'service_slug', 'review_id'], 'srm_service_review_unique');
            $table->index(['category_slug', 'service_slug', 'city_key', 'profile_id'], 'srm_service_city_profile_idx');
            $table->index(['category_slug', 'city_key', 'service_slug'], 'srm_category_city_service_idx');
            $table->index(['category_slug', 'service_slug', 'reviewed_at'], 'srm_service_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_review_matches');
    }
};
