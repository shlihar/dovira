<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->string('dovira_recommendation_status', 24)->nullable()->after('dovira_recommends');
        });

        DB::table('profiles')
            ->where('dovira_recommends', true)
            ->update(['dovira_recommendation_status' => 'recommend']);
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropColumn('dovira_recommendation_status');
        });
    }
};

