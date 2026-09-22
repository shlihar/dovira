<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Час першої відповіді бізнесу на заявку — потрібен для responsiveness_factor
 * і критеріїв бейджа #7/#8. Поки поле не наповнене, RatingCalculator тримає
 * фактор нейтральним (не караємо за брак даних).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_leads', function (Blueprint $table): void {
            $table->timestamp('responded_at')->nullable()->after('is_read');
        });
    }

    public function down(): void
    {
        Schema::table('profile_leads', function (Blueprint $table): void {
            $table->dropColumn('responded_at');
        });
    }
};
