<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Чат Telegram-бота для сповіщень власнику (нова заявка, новий відгук).
     * Заповнюється, коли користувач натискає /start у боті за deep-link-ом
     * із кабінету. Окремо від telegram_id (соцлогін): той не гарантує, що
     * користувач дозволив боту писати.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('telegram_notify_chat_id', 32)->nullable()->after('telegram_username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('telegram_notify_chat_id');
        });
    }
};
