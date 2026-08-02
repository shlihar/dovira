<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 64)->default('general');
            $table->string('key', 120)->unique();
            $table->json('value')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['group', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
