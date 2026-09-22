<?php

// Read-only audit snapshot. Run from the Laravel project directory:
// php /path/to/audit-catalog-export.php | gzip > snapshot.jsonl.gz
// No user accounts, review authors, email addresses or credentials are exported.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (DB::getDriverName() !== 'mysql') {
    throw new RuntimeException('This snapshot requires a MySQL read-only transaction.');
}

DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
DB::statement('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');

$emit = static function (string $table, array $row): void {
    echo json_encode(['table' => $table, 'row' => $row], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), "\n";
};

try {
    $emit('_meta', ['captured_at' => gmdate('c'), 'app_url' => config('app.url'), 'strict_indexing' => config('seo.strict_profile_indexing'), 'strict_min_description' => config('seo.strict_min_description')]);
    $tables = [
        'categories' => ['id', 'parent_id', 'name', 'slug', 'is_active', 'is_indexable'],
        'category_services' => ['id', 'category_id', 'name', 'slug'],
        'profiles' => ['id', 'name', 'slug', 'city', 'address', 'phone', 'website', 'status', 'is_published', 'show_in_catalog', 'description', 'description_original', 'short_description', 'dossier', 'dossier_verdict', 'ai_review_summary', 'ai_review_summary_source_count', 'seo_title', 'seo_description', 'reviews_count', 'rating_avg', 'created_at', 'updated_at'],
        'profile_category' => ['id', 'profile_id', 'category_id', 'is_primary'],
        'profile_category_service' => ['id', 'profile_id', 'category_service_id'],
        'profile_data_sources' => ['id', 'profile_id', 'source_type', 'title', 'url', 'found_fields', 'fetched_at'],
        'profile_reviews' => ['id', 'profile_id', 'status', 'rating', 'body', 'external_source_type', 'external_source_url', 'external_review_hash'],
    ];
    foreach ($tables as $table => $columns) {
        $columns = array_values(array_intersect($columns, Schema::getColumnListing($table)));
        $count = 0;
        foreach (DB::table($table)->select($columns)->lazyById(2000) as $record) {
            $row = (array) $record;
            if ($table === 'profile_data_sources') {
                $fields = json_decode($row['found_fields'] ?? '{}', true) ?: [];
                $row['found_fields'] = array_intersect_key($fields, array_flip(['name', 'title', 'place_id', 'placeId', 'city', 'category', 'categoryName', 'categories']));
                foreach (['listing', 'detail'] as $section) {
                    $row['found_fields'][$section] = array_intersect_key((array) ($fields[$section] ?? []), array_flip(['name', 'title', 'url', 'city', 'address', 'addresses', 'phones', 'website', 'services', 'directions']));
                }
            }
            if ($table === 'profile_reviews' && $row['status'] !== 'published') {
                $row['body'] = null;
            }
            $emit($table, $row);
            $count++;
        }
        $emit('_count', ['table' => $table, 'count' => $count]);
        file_put_contents('php://stderr', "$table: $count\n");
    }
    $emit('_complete', ['completed_at' => gmdate('c')]);
} finally {
    DB::statement('ROLLBACK');
}
