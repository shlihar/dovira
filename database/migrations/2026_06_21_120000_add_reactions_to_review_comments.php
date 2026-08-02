<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('review_replies', function (Blueprint $table) {
            if (! Schema::hasColumn('review_replies', 'like_count')) {
                $table->unsignedInteger('like_count')->default(0)->after('status');
            }

            if (! Schema::hasColumn('review_replies', 'dislike_count')) {
                $table->unsignedInteger('dislike_count')->default(0)->after('like_count');
            }
        });

        Schema::table('official_replies', function (Blueprint $table) {
            if (! Schema::hasColumn('official_replies', 'like_count')) {
                $table->unsignedInteger('like_count')->default(0)->after('body');
            }

            if (! Schema::hasColumn('official_replies', 'dislike_count')) {
                $table->unsignedInteger('dislike_count')->default(0)->after('like_count');
            }
        });

        if (! Schema::hasTable('review_reply_reactions')) {
            Schema::create('review_reply_reactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('review_reply_id')->constrained('review_replies')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('reaction', 16);
                $table->timestamps();

                $table->unique(['review_reply_id', 'user_id']);
                $table->index(['review_reply_id', 'reaction']);
            });
        }

        if (! Schema::hasTable('official_reply_reactions')) {
            Schema::create('official_reply_reactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('official_reply_id')->constrained('official_replies')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('reaction', 16);
                $table->timestamps();

                $table->unique(['official_reply_id', 'user_id']);
                $table->index(['official_reply_id', 'reaction']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('official_reply_reactions');
        Schema::dropIfExists('review_reply_reactions');

        Schema::table('official_replies', function (Blueprint $table) {
            if (Schema::hasColumn('official_replies', 'like_count')) {
                $table->dropColumn('like_count');
            }

            if (Schema::hasColumn('official_replies', 'dislike_count')) {
                $table->dropColumn('dislike_count');
            }
        });

        Schema::table('review_replies', function (Blueprint $table) {
            if (Schema::hasColumn('review_replies', 'like_count')) {
                $table->dropColumn('like_count');
            }

            if (Schema::hasColumn('review_replies', 'dislike_count')) {
                $table->dropColumn('dislike_count');
            }
        });
    }
};
