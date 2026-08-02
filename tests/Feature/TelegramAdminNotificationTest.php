<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramAdminMessage;
use App\Services\Payments\TelegramBotClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramAdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_telegram_job_does_not_send_during_tests(): void
    {
        config([
            'app.env' => 'testing',
            'services.telegram.bot_token' => 'real-looking-token',
        ]);

        $this->mock(TelegramBotClient::class)
            ->shouldNotReceive('sendMessage');

        (new SendTelegramAdminMessage('123', 'Новий тестовий відгук'))->handle();
    }
}
