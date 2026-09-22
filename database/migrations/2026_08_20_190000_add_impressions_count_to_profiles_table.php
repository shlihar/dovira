<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Покази (impressions): скільки разів картку профілю бачили в каталозі,
     * рейтингах, на сторінках інших профілів тощо — окремо від переглядів
     * самої сторінки профілю. База для майбутньої реклами.
     */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->unsignedBigInteger('impressions_count')->default(0)->after('views_count');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropColumn('impressions_count');
        });
    }
};
