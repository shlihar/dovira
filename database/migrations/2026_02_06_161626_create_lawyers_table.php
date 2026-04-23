<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lawyers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
            $table->string('full_name'); // ПІБ
            $table->string('certificate_number')->unique(); // Номер свідоцтва
            $table->date('certificate_issued_at')->nullable(); // Дата видачі
            $table->string('certificate_issuer')->nullable(); // Орган
            $table->string('decision_number')->nullable(); // Номер рішення
            $table->date('decision_at')->nullable(); // Дата прийняття рішення
            $table->string('email')->nullable();
            $table->string('photo_url')->nullable(); // Посилання на фото
            $table->boolean('is_suspended')->default(false); // Зупинено
            $table->text('notes')->nullable(); // Інші відомості
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lawyers');
    }
};
