<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Поля для нової системи рейтингів: джерело відгуку, верифікація автора,
 * результат антифроду (trust_score + виключення) і денормалізована вага.
 * Нічого не видаляємо — виключені відгуки лишаються видимими з поміткою.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_reviews', function (Blueprint $table): void {
            // Джерело: google_import | platform | confirmed_request.
            $table->string('source', 32)->default('google_import')->index()->after('external_source_type');
            $table->string('source_reference', 255)->nullable()->after('source');
            // Заявка, з якої виріс відгук (для source=confirmed_request).
            $table->foreignId('origin_lead_id')->nullable()->after('source_reference')
                ->constrained('profile_leads')->nullOnDelete();

            // Верифікація автора (для w_verification).
            $table->boolean('author_phone_verified')->default(false)->after('author_ip');
            $table->boolean('author_email_verified')->default(false)->after('author_phone_verified');

            // Антифрод. trust_score 0..1; нижче порога → виключений з розрахунку,
            // але лишається видимим. manually_reviewed «заморожує» від антифроду.
            $table->float('trust_score')->default(1.0)->after('author_email_verified');
            $table->boolean('is_excluded_from_rating')->default(false)->index()->after('trust_score');
            $table->string('exclusion_reason', 64)->nullable()->after('is_excluded_from_rating');
            $table->boolean('manually_reviewed')->default(false)->after('exclusion_reason');

            // Денормалізована вага (перераховується батчем).
            $table->float('computed_weight')->default(1.0)->after('manually_reviewed');
            $table->timestamp('weight_computed_at')->nullable()->after('computed_weight');
        });

        // Backfill джерела найконсервативніше: зовнішні → google_import,
        // «рідні» (без external_source_type) → platform. Джерело не вигадуємо.
        DB::table('profile_reviews')->whereNotNull('external_source_type')
            ->where('external_source_type', '!=', '')
            ->update(['source' => 'google_import']);
        DB::table('profile_reviews')->where(function ($q): void {
            $q->whereNull('external_source_type')->orWhere('external_source_type', '');
        })->update(['source' => 'platform']);
    }

    public function down(): void
    {
        Schema::table('profile_reviews', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('origin_lead_id');
            $table->dropColumn([
                'source', 'source_reference',
                'author_phone_verified', 'author_email_verified',
                'trust_score', 'is_excluded_from_rating', 'exclusion_reason',
                'manually_reviewed', 'computed_weight', 'weight_computed_at',
            ]);
        });
    }
};
