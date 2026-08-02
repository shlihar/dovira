<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('profile_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visitor_id', 100)->nullable();

            $table->string('event_type', 64);
            $table->string('source', 120)->nullable();
            $table->string('internal_source', 120)->nullable();
            $table->string('referrer')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('target_url')->nullable();
            $table->string('device_type', 32)->nullable();
            $table->string('country', 80)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('ip_hash', 80)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'event_type'], 'profile_events_profile_event_idx');
            $table->index(['profile_id', 'created_at'], 'profile_events_profile_created_idx');
            $table->index('source');
            $table->index('visitor_id');
        });

        Schema::table('profiles', function (Blueprint $table): void {
            $table->unsignedBigInteger('views_count')->default(0)->after('reviews_count');
            $table->unsignedBigInteger('unique_views_count')->default(0)->after('views_count');
            $table->unsignedBigInteger('website_clicks_count')->default(0)->after('unique_views_count');
            $table->unsignedBigInteger('contact_clicks_count')->default(0)->after('website_clicks_count');
            $table->decimal('popularity_score', 12, 2)->default(0)->after('contact_clicks_count');

            $table->index('views_count');
            $table->index('website_clicks_count');
            $table->index('contact_clicks_count');
            $table->index('popularity_score');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropIndex(['views_count']);
            $table->dropIndex(['website_clicks_count']);
            $table->dropIndex(['contact_clicks_count']);
            $table->dropIndex(['popularity_score']);
            $table->dropColumn([
                'views_count',
                'unique_views_count',
                'website_clicks_count',
                'contact_clicks_count',
                'popularity_score',
            ]);
        });

        Schema::dropIfExists('profile_events');
    }
};

