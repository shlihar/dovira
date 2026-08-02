<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('profile_reviews', function (Blueprint $table) {
            $table->text('pros')->nullable()->after('body');
            $table->text('cons')->nullable()->after('pros');
            $table->date('interaction_date')->nullable()->after('cons');
            $table->ipAddress('author_ip')->nullable()->after('author_email');
            $table->text('author_user_agent')->nullable()->after('author_ip');
            $table->text('moderation_note')->nullable()->after('status');

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('profile_reviews', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn([
                'pros',
                'cons',
                'interaction_date',
                'author_ip',
                'author_user_agent',
                'moderation_note',
            ]);
        });
    }
};
