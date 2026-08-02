<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pro_subscription_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pro_subscription_id')->nullable()->constrained('pro_subscriptions')->nullOnDelete();
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 32)->default('test_gateway');
            $table->string('reference', 64)->unique();
            $table->string('plan', 32)->default('business');
            $table->string('billing_period', 16)->default('month');
            $table->string('description', 255);
            $table->unsignedInteger('amount')->default(0);
            $table->string('currency', 8)->default('UAH');
            $table->string('status', 32)->default('paid');
            $table->timestamp('paid_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'paid_at']);
            $table->index(['pro_subscription_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pro_subscription_payments');
    }
};
