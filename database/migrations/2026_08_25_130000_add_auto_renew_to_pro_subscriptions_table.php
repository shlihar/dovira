<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Автопродовження PRO: monopay зберігає картку при першій оплаті
     * (saveCardData), токен живе на підписці, і команда
     * dovira:pro:renew-subscriptions списує наступний період автоматично.
     */
    public function up(): void
    {
        Schema::table('pro_subscriptions', function (Blueprint $table): void {
            $table->boolean('auto_renew')->default(false)->after('status');
            $table->string('monopay_card_token', 128)->nullable()->after('auto_renew');
            $table->string('monopay_wallet_id', 64)->nullable()->after('monopay_card_token');
            $table->unsignedTinyInteger('renew_attempts')->default(0)->after('monopay_wallet_id');
            $table->timestamp('last_renew_failed_at')->nullable()->after('renew_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('pro_subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['auto_renew', 'monopay_card_token', 'monopay_wallet_id', 'renew_attempts', 'last_renew_failed_at']);
        });
    }
};
