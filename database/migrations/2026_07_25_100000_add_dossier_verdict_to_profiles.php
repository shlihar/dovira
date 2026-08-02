<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Світлофор-вердикт досьє: red/yellow/green + короткий підсумок одним рядком.
 * Рендериться бейджем угорі блоку досьє — «считується» за секунду.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->string('dossier_verdict', 10)->nullable()->after('dossier_generated_at');
            $table->string('dossier_verdict_note', 255)->nullable()->after('dossier_verdict');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['dossier_verdict', 'dossier_verdict_note']);
        });
    }
};
