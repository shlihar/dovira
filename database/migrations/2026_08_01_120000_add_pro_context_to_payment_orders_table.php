<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_orders', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->foreignId('profile_id')->nullable()->after('user_id')->constrained('profiles')->nullOnDelete();
            $table->string('product_type', 32)->default('generic')->after('method');
            $table->string('description')->nullable()->after('currency');
            $table->json('meta')->nullable()->after('payer_telegram_id');

            $table->index(['profile_id', 'product_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_orders', function (Blueprint $table): void {
            $table->dropIndex(['profile_id', 'product_type', 'status']);
            $table->dropConstrainedForeignId('profile_id');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['product_type', 'description', 'meta']);
        });
    }
};
