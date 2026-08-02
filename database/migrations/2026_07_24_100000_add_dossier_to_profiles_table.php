<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-досьє профілю: розгорнутий репутаційний матеріал (markdown), який
 * збирає ШІ з відкритих джерел (реєстри, суди, новини, сайт) і який можна
 * редагувати в адмінці та (з позначкою) власником у PRO-кабінеті.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->longText('dossier')->nullable()->after('description');
            // ai | admin | owner — хто востаннє редагував текст.
            $table->string('dossier_source', 20)->nullable()->after('dossier');
            $table->timestamp('dossier_generated_at')->nullable()->after('dossier_source');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['dossier', 'dossier_source', 'dossier_generated_at']);
        });
    }
};
