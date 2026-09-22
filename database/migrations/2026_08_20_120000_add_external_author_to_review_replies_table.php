<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ім'я зовнішнього коментатора під відгуком (напр. з Threads), коли коментар
 * не належить зареєстрованому користувачу (author_user_id = null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_replies', function (Blueprint $table): void {
            $table->string('external_author', 255)->nullable()->after('author_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('review_replies', function (Blueprint $table): void {
            $table->dropColumn('external_author');
        });
    }
};
