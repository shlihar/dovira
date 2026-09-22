<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Індекси під нове ранжування каталогу.
     *
     * 1) (status, popularity_score) — дефолтна органічна видача: рівність по
     *    статусу + сортування за популярністю. Наявний profiles_home_popular_idx
     *    не підходить: між status і popularity_score у ньому стоять
     *    is_published/show_in_catalog, які каталог не фільтрує.
     * 2) (status, is_pro, popularity_score) — вибірка PRO-блоку (рівність по
     *    двох колонках + порядок за популярністю). Наявний
     *    profiles_status_is_verified_is_pro_index не годиться: is_verified
     *    посередині розриває префікс.
     */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->index(['status', 'popularity_score'], 'profiles_status_popularity_idx');
            $table->index(['status', 'is_pro', 'popularity_score'], 'profiles_status_pro_popularity_idx');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropIndex('profiles_status_popularity_idx');
            $table->dropIndex('profiles_status_pro_popularity_idx');
        });
    }
};
