<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('profile_leads')) {
            return;
        }

        Schema::create('profile_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('phone', 40);
            $table->string('message', 1000)->nullable();
            $table->string('source', 40)->default('profile_form'); // звідки лід
            $table->string('status', 20)->default('new');          // new | contacted | archived
            $table->boolean('is_read')->default(false);
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'created_at']);
            $table->index(['profile_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_leads');
    }
};
