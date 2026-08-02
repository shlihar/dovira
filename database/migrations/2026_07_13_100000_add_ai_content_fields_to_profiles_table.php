<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            // Бекап опису до AI-переписування: якщо результат не сподобається,
            // завжди можна відкотитись.
            $table->longText('description_original')->nullable()->after('description');
            $table->timestamp('description_rewritten_at')->nullable()->after('description_original');

            // AI-підсумок відгуків: {summary, positives[], negatives[]}.
            // source_count — скільки відгуків було на момент генерації,
            // щоб детектити застарілі підсумки.
            $table->json('ai_review_summary')->nullable()->after('description_rewritten_at');
            $table->timestamp('ai_review_summary_generated_at')->nullable()->after('ai_review_summary');
            $table->unsignedInteger('ai_review_summary_source_count')->nullable()->after('ai_review_summary_generated_at');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn([
                'description_original',
                'description_rewritten_at',
                'ai_review_summary',
                'ai_review_summary_generated_at',
                'ai_review_summary_source_count',
            ]);
        });
    }
};
