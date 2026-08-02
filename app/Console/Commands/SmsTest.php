<?php

namespace App\Console\Commands;

use App\Services\Sms\SmsService;
use Illuminate\Console\Command;

/**
 * Перевірка налаштування SMS (TurboSMS): показує баланс і, якщо вказано
 * номер, шле тестове повідомлення. Приклад: php artisan sms:test 380501234567
 */
class SmsTest extends Command
{
    protected $signature = 'sms:test {phone? : Номер для тестового SMS (необов’язково)}';

    protected $description = 'Перевірити налаштування SMS (баланс + тестова відправка)';

    public function handle(SmsService $sms): int
    {
        if (! $sms->enabled()) {
            $this->error('SMS вимкнено: не заданий TURBOSMS_TOKEN у .env.');

            return self::FAILURE;
        }

        $this->info('Відправник: ' . config('services.sms.turbosms.sender'));

        $balance = $sms->balance();
        if ($balance === null) {
            $this->error('Не вдалося отримати баланс — перевірте токен (див. storage/logs).');

            return self::FAILURE;
        }
        $this->info('Токен робочий. Баланс: ' . $balance);

        $phone = $this->argument('phone');
        if (! $phone) {
            $this->line('Номер не вказано — тестове SMS не надсилалось.');

            return self::SUCCESS;
        }

        $this->line('Надсилаю тестове SMS на ' . $phone . ' …');
        $ok = $sms->send($phone, 'Dovira: тестове SMS. Код підтвердження: 123456');

        if ($ok) {
            $this->info('✅ Надіслано.');

            return self::SUCCESS;
        }

        $this->error('❌ Не надіслано — див. storage/logs/laravel.log (часта причина: неузгоджений sender).');

        return self::FAILURE;
    }
}
