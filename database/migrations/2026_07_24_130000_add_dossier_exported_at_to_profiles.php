<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Мітка вигрузки профілю в пакет на генерацію досьє: раз вигружений —
 * у наступні пакети не потрапляє (щоб не платити за дублі генерації).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->timestamp('dossier_exported_at')->nullable()->after('dossier_generated_at');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('dossier_exported_at');
        });
    }
};
