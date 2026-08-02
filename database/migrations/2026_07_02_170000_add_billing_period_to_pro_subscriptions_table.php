<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pro_subscriptions', function (Blueprint $table): void {
            $table->string('billing_period', 16)->default('month')->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('pro_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('billing_period');
        });
    }
};
