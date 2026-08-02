<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->text('short_description')->nullable()->after('name');
            $table->json('social_links')->nullable()->after('logo_url');
            $table->boolean('is_featured')->default(false)->after('is_pro');
            $table->string('seo_title')->nullable()->after('recommend_percent');
            $table->text('seo_description')->nullable()->after('seo_title');

            $table->index(['is_featured', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropIndex(['is_featured', 'status']);
            $table->dropColumn([
                'short_description',
                'social_links',
                'is_featured',
                'seo_title',
                'seo_description',
            ]);
        });
    }
};
