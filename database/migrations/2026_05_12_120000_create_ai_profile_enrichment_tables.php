<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_enrichment_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('default_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('source_type')->default('manual');
            $table->string('status')->default('draft');
            $table->string('default_city')->nullable();
            $table->string('default_country')->nullable();
            $table->string('language', 12)->default('uk');
            $table->json('options')->nullable();
            $table->longText('input_text')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('drafts_created')->default(0);
            $table->unsignedInteger('profiles_updated')->default(0);
            $table->unsignedInteger('duplicates_found')->default(0);
            $table->unsignedInteger('errors_count')->default(0);
            $table->unsignedInteger('needs_review_count')->default(0);
            $table->unsignedInteger('sources_found')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['default_category_id', 'status']);
        });

        Schema::create('ai_enrichment_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('ai_enrichment_batches')->cascadeOnDelete();
            $table->foreignId('profile_id')->nullable()->constrained('profiles')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->unsignedInteger('row_number')->nullable();
            $table->string('raw_name');
            $table->string('raw_city')->nullable();
            $table->string('raw_phone')->nullable();
            $table->string('raw_website')->nullable();
            $table->string('raw_source_url')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('confidence_score')->default(0);
            $table->json('suggested_data')->nullable();
            $table->json('duplicate_candidates')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'status']);
            $table->index(['profile_id', 'status']);
            $table->index(['category_id', 'status']);
        });

        Schema::create('profile_data_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->nullable()->constrained('profiles')->nullOnDelete();
            $table->foreignId('ai_enrichment_task_id')->nullable()->constrained('ai_enrichment_tasks')->nullOnDelete();
            $table->string('source_type')->default('manual');
            $table->string('title')->nullable();
            $table->text('url')->nullable();
            $table->json('found_fields')->nullable();
            $table->unsignedTinyInteger('confidence_score')->default(0);
            $table->timestamp('fetched_at')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->index(['profile_id', 'source_type']);
            $table->index(['ai_enrichment_task_id', 'status']);
        });

        Schema::create('external_profile_mentions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->nullable()->constrained('profiles')->nullOnDelete();
            $table->foreignId('ai_enrichment_task_id')->nullable()->constrained('ai_enrichment_tasks')->nullOnDelete();
            $table->string('source_type')->default('external_review');
            $table->string('title')->nullable();
            $table->text('url')->nullable();
            $table->decimal('external_rating', 3, 2)->nullable();
            $table->unsignedInteger('external_reviews_count')->nullable();
            $table->string('sentiment')->nullable();
            $table->string('topic')->nullable();
            $table->text('summary')->nullable();
            $table->unsignedTinyInteger('confidence_score')->default(0);
            $table->string('status')->default('pending');
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'status']);
            $table->index(['ai_enrichment_task_id', 'status']);
        });

        Schema::table('profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('profiles', 'ai_confidence_score')) {
                $table->unsignedTinyInteger('ai_confidence_score')->default(0)->after('popularity_score');
            }

            if (! Schema::hasColumn('profiles', 'ai_enrichment_status')) {
                $table->string('ai_enrichment_status')->nullable()->after('ai_confidence_score');
            }

            if (! Schema::hasColumn('profiles', 'ai_enriched_at')) {
                $table->timestamp('ai_enriched_at')->nullable()->after('ai_enrichment_status');
            }

            if (! Schema::hasColumn('profiles', 'ai_suggested_data')) {
                $table->json('ai_suggested_data')->nullable()->after('ai_enriched_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            foreach (['ai_suggested_data', 'ai_enriched_at', 'ai_enrichment_status', 'ai_confidence_score'] as $column) {
                if (Schema::hasColumn('profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('external_profile_mentions');
        Schema::dropIfExists('profile_data_sources');
        Schema::dropIfExists('ai_enrichment_tasks');
        Schema::dropIfExists('ai_enrichment_batches');
    }
};
