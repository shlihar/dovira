<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_reviews', function (Blueprint $table) {
            if (! Schema::hasColumn('profile_reviews', 'author_phone')) {
                $table->string('author_phone', 40)->nullable()->after('author_email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('profile_reviews', function (Blueprint $table) {
            if (Schema::hasColumn('profile_reviews', 'author_phone')) {
                $table->dropColumn('author_phone');
            }
        });
    }
};
