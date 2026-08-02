<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('profile_reviews', function (Blueprint $table) {
            $table->unsignedInteger('like_count')->default(0)->after('helpful_count');
            $table->unsignedInteger('dislike_count')->default(0)->after('like_count');
        });

        Schema::table('review_replies', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('author_user_id')->constrained('review_replies')->nullOnDelete();
            $table->string('status', 32)->default('published')->after('is_official');
            $table->index(['profile_review_id', 'parent_id', 'status', 'created_at'], 'review_replies_thread_idx');
        });

        Schema::create('profile_review_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_review_id')->constrained('profile_reviews')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reaction', 16);
            $table->timestamps();

            $table->unique(['profile_review_id', 'user_id']);
            $table->index(['profile_review_id', 'reaction']);
        });

        DB::table('profile_reviews')->update([
            'like_count' => DB::raw('COALESCE(helpful_count, 0)'),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_review_reactions');

        Schema::table('review_replies', function (Blueprint $table) {
            $table->dropIndex('review_replies_thread_idx');
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('status');
        });

        Schema::table('profile_reviews', function (Blueprint $table) {
            $table->dropColumn(['like_count', 'dislike_count']);
        });
    }
};
