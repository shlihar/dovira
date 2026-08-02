<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Режим головної кнопки звʼязку на публічному профілі:
     *  - link      — веде за contact_cta_url (як було);
     *  - lead_form — відкриває попап із формою заявки.
     */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->string('contact_cta_mode', 20)->default('link')->after('contact_cta_url');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropColumn('contact_cta_mode');
        });
    }
};
