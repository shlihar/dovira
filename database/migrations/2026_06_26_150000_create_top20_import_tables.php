<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('top20_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('status')->default('draft');
            $table->string('city_code', 16)->nullable();
            $table->string('city_name')->nullable();
            $table->string('region_name')->nullable();
            $table->text('listing_url')->nullable();
            $table->json('options')->nullable();
            $table->unsignedInteger('pages_visited')->default(0);
            $table->unsignedInteger('cards_found')->default(0);
            $table->unsignedInteger('profiles_considered')->default(0);
            $table->unsignedInteger('profiles_created')->default(0);
            $table->unsignedInteger('profiles_updated')->default(0);
            $table->unsignedInteger('profiles_skipped')->default(0);
            $table->unsignedInteger('reviews_created')->default(0);
            $table->unsignedInteger('reviews_updated')->default(0);
            $table->unsignedInteger('reviews_hidden')->default(0);
            $table->unsignedInteger('errors_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['category_id', 'status']);
        });

        Schema::create('top20_import_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('top20_import_batches')->cascadeOnDelete();
            $table->foreignId('profile_id')->nullable()->constrained('profiles')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->string('raw_name')->nullable();
            $table->string('raw_city')->nullable();
            $table->text('top20_url')->nullable();
            $table->json('raw_payload')->nullable();
            $table->unsignedInteger('reviews_created')->default(0);
            $table->unsignedInteger('reviews_updated')->default(0);
            $table->unsignedInteger('reviews_hidden')->default(0);
            $table->boolean('profile_was_created')->default(false);
            $table->boolean('profile_was_updated')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'top20_url']);
            $table->index(['batch_id', 'status']);
            $table->index(['profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('top20_import_items');
        Schema::dropIfExists('top20_import_batches');
    }
};
