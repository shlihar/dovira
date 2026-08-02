<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_sections', function (Blueprint $table) {
            $table->id();
            $table->string('page_key', 64);
            $table->string('section_key', 64);
            $table->string('title')->nullable();
            $table->text('subtitle')->nullable();
            $table->json('cards')->nullable();
            $table->string('button_text')->nullable();
            $table->string('button_link')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['page_key', 'section_key']);
            $table->index(['page_key', 'sort_order', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_sections');
    }
};
