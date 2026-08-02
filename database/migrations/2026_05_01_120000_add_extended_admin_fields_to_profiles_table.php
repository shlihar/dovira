<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->string('banner_url')->nullable()->after('logo_url');
            $table->json('gallery')->nullable()->after('banner_url');
            $table->boolean('is_published')->default(true)->after('status');
            $table->boolean('show_in_catalog')->default(true)->after('is_published');
            $table->integer('sort_priority')->default(0)->after('show_in_catalog');
            $table->string('og_image_url')->nullable()->after('seo_description');
            $table->text('internal_note')->nullable()->after('og_image_url');

            $table->foreignId('created_by_user_id')->nullable()->after('owner_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->after('created_by_user_id')->constrained('users')->nullOnDelete();

            $table->index(['show_in_catalog', 'sort_priority']);
            $table->index(['created_by_user_id']);
            $table->index(['updated_by_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropConstrainedForeignId('updated_by_user_id');

            $table->dropIndex(['show_in_catalog', 'sort_priority']);
            $table->dropIndex(['created_by_user_id']);
            $table->dropIndex(['updated_by_user_id']);

            $table->dropColumn([
                'banner_url',
                'gallery',
                'is_published',
                'show_in_catalog',
                'sort_priority',
                'og_image_url',
                'internal_note',
            ]);
        });
    }
};
