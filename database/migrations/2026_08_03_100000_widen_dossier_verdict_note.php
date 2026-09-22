<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            // Було VARCHAR(255) — підсумок-плашка обрізався. Робимо TEXT.
            $table->text('dossier_verdict_note')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->string('dossier_verdict_note', 255)->nullable()->change();
        });
    }
};
