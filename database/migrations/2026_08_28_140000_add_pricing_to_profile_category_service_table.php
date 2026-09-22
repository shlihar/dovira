<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Прайс профілю: послуга перестає бути тегом і стає рядком прайсу
     * (стратегія, розділи 03/06). Поля живуть на привʼязці профіль↔послуга:
     * назва — завжди з довідника category_services, ціна й опис — свої.
     * Ціни заповнює лише PRO (гейт у контролері), FREE лишається з тегами.
     */
    public function up(): void
    {
        Schema::table('profile_category_service', function (Blueprint $table): void {
            $table->string('price_type', 16)->nullable()->after('category_service_id'); // fixed|from|range|negotiable
            $table->unsignedInteger('price')->nullable()->after('price_type');          // грн
            $table->unsignedInteger('price_max')->nullable()->after('price');           // для range
            $table->string('price_unit', 32)->nullable()->after('price_max');           // «за годину» тощо (v2 UI)
            $table->string('description', 300)->nullable()->after('price_unit');
            $table->string('duration', 60)->nullable()->after('description');           // «30–60 хв» (v2 UI)
            $table->unsignedSmallInteger('sort_order')->default(0)->after('duration');
            $table->boolean('is_highlighted')->default(false)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('profile_category_service', function (Blueprint $table): void {
            $table->dropColumn(['price_type', 'price', 'price_max', 'price_unit', 'description', 'duration', 'sort_order', 'is_highlighted']);
        });
    }
};
