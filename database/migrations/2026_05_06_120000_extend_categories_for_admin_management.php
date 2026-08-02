<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (! Schema::hasColumn('categories', 'short_description')) {
                $table->text('short_description')->nullable()->after('description');
            }
            if (! Schema::hasColumn('categories', 'icon')) {
                $table->string('icon')->nullable()->after('short_description');
            }
            if (! Schema::hasColumn('categories', 'image')) {
                $table->string('image')->nullable()->after('icon');
            }
            if (! Schema::hasColumn('categories', 'cover_image')) {
                $table->string('cover_image')->nullable()->after('image');
            }
            if (! Schema::hasColumn('categories', 'status')) {
                $table->string('status', 32)->default('active')->after('cover_image');
            }
            if (! Schema::hasColumn('categories', 'show_on_homepage')) {
                $table->boolean('show_on_homepage')->default(false)->after('status');
            }
            if (! Schema::hasColumn('categories', 'show_in_menu')) {
                $table->boolean('show_in_menu')->default(true)->after('show_on_homepage');
            }
            if (! Schema::hasColumn('categories', 'show_in_footer')) {
                $table->boolean('show_in_footer')->default(false)->after('show_in_menu');
            }
            if (! Schema::hasColumn('categories', 'show_in_catalog')) {
                $table->boolean('show_in_catalog')->default(true)->after('show_in_footer');
            }
            if (! Schema::hasColumn('categories', 'is_indexable')) {
                $table->boolean('is_indexable')->default(true)->after('show_in_catalog');
            }
            if (! Schema::hasColumn('categories', 'pro_enabled')) {
                $table->boolean('pro_enabled')->default(true)->after('is_indexable');
            }
            if (! Schema::hasColumn('categories', 'pro_price')) {
                $table->decimal('pro_price', 10, 2)->nullable()->after('pro_enabled');
            }
            if (! Schema::hasColumn('categories', 'seo_title')) {
                $table->string('seo_title')->nullable()->after('sort_order');
            }
            if (! Schema::hasColumn('categories', 'seo_description')) {
                $table->string('seo_description', 160)->nullable()->after('seo_title');
            }
            if (! Schema::hasColumn('categories', 'seo_h1')) {
                $table->string('seo_h1')->nullable()->after('seo_description');
            }
            if (! Schema::hasColumn('categories', 'seo_text')) {
                $table->text('seo_text')->nullable()->after('seo_h1');
            }
            if (! Schema::hasColumn('categories', 'canonical_url')) {
                $table->string('canonical_url')->nullable()->after('seo_text');
            }
            if (! Schema::hasColumn('categories', 'og_title')) {
                $table->string('og_title')->nullable()->after('canonical_url');
            }
            if (! Schema::hasColumn('categories', 'og_description')) {
                $table->string('og_description', 160)->nullable()->after('og_title');
            }
            if (! Schema::hasColumn('categories', 'og_image')) {
                $table->string('og_image')->nullable()->after('og_description');
            }
            if (! Schema::hasColumn('categories', 'profiles_count')) {
                $table->unsignedInteger('profiles_count')->default(0)->after('og_image');
            }
            if (! Schema::hasColumn('categories', 'reviews_count')) {
                $table->unsignedInteger('reviews_count')->default(0)->after('profiles_count');
            }
            if (! Schema::hasColumn('categories', 'views_count')) {
                $table->unsignedInteger('views_count')->default(0)->after('reviews_count');
            }
            if (! Schema::hasColumn('categories', 'pro_profiles_count')) {
                $table->unsignedInteger('pro_profiles_count')->default(0)->after('views_count');
            }
            if (! Schema::hasColumn('categories', 'popularity_score')) {
                $table->decimal('popularity_score', 12, 2)->default(0)->after('pro_profiles_count');
            }
        });

        DB::statement('create index if not exists categories_status_sort_order_idx on categories (status, sort_order)');
        DB::statement('create index if not exists categories_parent_id_idx on categories (parent_id)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists categories_status_sort_order_idx');
        DB::statement('drop index if exists categories_parent_id_idx');

        Schema::table('categories', function (Blueprint $table) {
            $columns = [
                'short_description',
                'icon',
                'image',
                'cover_image',
                'status',
                'show_on_homepage',
                'show_in_menu',
                'show_in_footer',
                'show_in_catalog',
                'is_indexable',
                'pro_enabled',
                'pro_price',
                'seo_title',
                'seo_description',
                'seo_h1',
                'seo_text',
                'canonical_url',
                'og_title',
                'og_description',
                'og_image',
                'profiles_count',
                'reviews_count',
                'views_count',
                'pro_profiles_count',
                'popularity_score',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('categories', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
