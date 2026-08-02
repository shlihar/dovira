<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('category_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('show_in_catalog')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('profiles_count')->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('pro_profiles_count')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'slug']);
            $table->index(['category_id', 'is_active', 'sort_order']);
        });

        Schema::create('profile_category_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('category_service_id')->constrained('category_services')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['profile_id', 'category_service_id'], 'profile_category_service_unique');
            $table->index('category_service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_category_service');
        Schema::dropIfExists('category_services');
    }
};

