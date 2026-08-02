<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_page_events', function (Blueprint $table) {
            // UI-події (пошук, фільтри, воронка відгуків, CTA): label — головне
            // значення події (пошуковий запит, назва фільтра, slug профілю).
            $table->string('event_label')->nullable()->after('event_type');
            $table->index(['event_type', 'event_label'], 'site_page_events_type_label_index');
            $table->index(['event_type', 'created_at'], 'site_page_events_type_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('site_page_events', function (Blueprint $table) {
            $table->dropIndex('site_page_events_type_label_index');
            $table->dropIndex('site_page_events_type_created_index');
            $table->dropColumn('event_label');
        });
    }
};
