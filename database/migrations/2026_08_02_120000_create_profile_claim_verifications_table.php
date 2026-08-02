<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Коди підтвердження прав на профіль (OTP): надсилаємо код на email/телефон,
 * ВКАЗАНИЙ У ПРОФІЛІ, — так автоматично доводимо, що заявник контролює бізнес.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_claim_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 16);              // email | phone
            $table->string('destination', 255);         // куди надіслали (повний контакт, для аудиту)
            $table->string('code_hash', 64);            // sha256 коду
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_claim_verifications');
    }
};
