<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->string('method', 16); // stars | crypto
            $table->string('status', 16)->default('pending'); // pending | paid
            $table->string('amount', 32);
            $table->string('currency', 16); // XTR | USDT | ...
            $table->string('provider_invoice_id')->nullable(); // Crypto Pay invoice_id
            $table->string('provider_charge_id')->nullable(); // telegram_payment_charge_id
            $table->string('payer_telegram_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_orders');
    }
};
