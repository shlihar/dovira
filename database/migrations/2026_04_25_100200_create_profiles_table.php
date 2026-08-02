<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();

            $table->string('type', 32)->default('company');
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->string('website')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->string('logo_url')->nullable();

            $table->boolean('is_verified')->default(false);
            $table->boolean('is_pro')->default(false);
            $table->string('status', 32)->default('active');

            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->unsignedTinyInteger('recommend_percent')->default(0);

            $table->timestamps();

            $table->index(['status', 'is_verified', 'is_pro']);
            $table->index(['type', 'city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
