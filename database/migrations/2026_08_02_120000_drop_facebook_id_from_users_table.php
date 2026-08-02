<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'facebook_id')) {
            return;
        }

        // Спершу знімаємо unique-індекс: MySQL прибирає його разом із колонкою
        // автоматично, а SQLite (тести) без цього падає на drop column.
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['facebook_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('facebook_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'facebook_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('facebook_id')->nullable()->unique()->after('google_id');
        });
    }
};
