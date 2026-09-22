<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Аудит кожної зміни бейджа «Dovira рекомендує»: коли, чому (які критерії
 * спрацювали) і повний знімок метрик на момент рішення. Дає відповідь
 * «чому профіль отримав/втратив бейдж» у будь-який момент.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badge_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('action', 16); // granted | revoked | at_risk | recovered
            $table->json('reason')->nullable();   // які критерії спрацювали
            $table->json('snapshot')->nullable(); // стан метрик на момент рішення
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('badge_audit_logs');
    }
};
