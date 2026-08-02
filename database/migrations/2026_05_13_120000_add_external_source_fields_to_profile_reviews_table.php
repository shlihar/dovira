<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_reviews', function (Blueprint $table): void {
            if (! Schema::hasColumn('profile_reviews', 'external_source_url')) {
                $table->text('external_source_url')->nullable()->after('published_at');
            }

            if (! Schema::hasColumn('profile_reviews', 'external_source_type')) {
                $table->string('external_source_type', 64)->nullable()->after('external_source_url');
            }

            if (! Schema::hasColumn('profile_reviews', 'external_review_author')) {
                $table->string('external_review_author')->nullable()->after('external_source_type');
            }

            if (! Schema::hasColumn('profile_reviews', 'external_review_date')) {
                $table->date('external_review_date')->nullable()->after('external_review_author');
            }
        });
    }

    public function down(): void
    {
        Schema::table('profile_reviews', function (Blueprint $table): void {
            foreach (['external_review_date', 'external_review_author', 'external_source_type', 'external_source_url'] as $column) {
                if (Schema::hasColumn('profile_reviews', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
