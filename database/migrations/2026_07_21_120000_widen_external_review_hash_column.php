<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Колонка була string(40) під sha1, але частина імпортерів пише sha256
 * (64 симв.): у strict-режимі MySQL це падало з «Data too long», у
 * non-strict — мовчки обрізалось. Розширюємо до 64.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_reviews', function (Blueprint $table) {
            $table->string('external_review_hash', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('profile_reviews', function (Blueprint $table) {
            $table->string('external_review_hash', 40)->nullable()->change();
        });
    }
};
