<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique()->after('avatar_url');
            $table->string('facebook_id')->nullable()->unique()->after('google_id');
            $table->string('telegram_id')->nullable()->unique()->after('facebook_id');
            $table->string('telegram_username')->nullable()->after('telegram_id');
            $table->string('auth_provider', 32)->nullable()->after('telegram_username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'google_id',
                'facebook_id',
                'telegram_id',
                'telegram_username',
                'auth_provider',
            ]);
        });
    }
};
