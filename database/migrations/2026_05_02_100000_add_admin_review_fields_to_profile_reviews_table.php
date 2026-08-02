<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('profile_reviews', function (Blueprint $table) {
            $table->boolean('is_anonymous')->default(false)->after('author_email');
            $table->boolean('is_suspicious')->default(false)->after('is_verified_purchase');
            $table->boolean('is_featured')->default(false)->after('is_suspicious');
            $table->string('verification_type', 32)->nullable()->after('is_verified_purchase');
            $table->string('moderation_reason', 64)->nullable()->after('moderation_note');
            $table->text('admin_note')->nullable()->after('moderation_reason');
            $table->decimal('risk_score', 5, 2)->default(0)->after('author_user_agent');

            $table->index(['is_suspicious', 'status']);
            $table->index(['is_anonymous', 'status']);
            $table->index(['is_verified_purchase', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('profile_reviews', function (Blueprint $table) {
            $table->dropIndex(['is_suspicious', 'status']);
            $table->dropIndex(['is_anonymous', 'status']);
            $table->dropIndex(['is_verified_purchase', 'status']);

            $table->dropColumn([
                'is_anonymous',
                'is_suspicious',
                'is_featured',
                'verification_type',
                'moderation_reason',
                'admin_note',
                'risk_score',
            ]);
        });
    }
};
