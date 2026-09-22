<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Запити на перегляд досьє (PRO): власник пише, що в досьє неточне або
     * що змінити, — команда перевіряє факти й переробляє досьє вручну
     * (конвеєр експорт → генерація → імпорт). Бізнес не редагує досьє сам.
     */
    public function up(): void
    {
        Schema::create('dossier_revision_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('message');
            $table->string('status', 24)->default('pending'); // pending | in_review | done | rejected
            $table->text('admin_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_revision_requests');
    }
};
