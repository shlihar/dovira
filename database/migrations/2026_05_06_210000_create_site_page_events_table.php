<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_page_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('visitor_id', 64)->nullable();
            $table->string('event_type', 64);
            $table->string('page_path', 255)->nullable();
            $table->string('page_url', 1000)->nullable();
            $table->string('source', 120)->nullable();
            $table->string('internal_source', 120)->nullable();
            $table->string('referrer', 1000)->nullable();
            $table->string('utm_source', 255)->nullable();
            $table->string('utm_medium', 255)->nullable();
            $table->string('utm_campaign', 255)->nullable();
            $table->string('utm_content', 255)->nullable();
            $table->string('utm_term', 255)->nullable();
            $table->string('device_type', 32)->nullable();
            $table->string('country', 80)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('ip_hash', 128)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['event_type', 'created_at']);
            $table->index(['visitor_id', 'event_type']);
            $table->index('source');
            $table->index('page_path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_page_events');
    }
};

