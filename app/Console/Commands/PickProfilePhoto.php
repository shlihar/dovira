<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Services\AiEnrichment\PortraitPickerService;
use Illuminate\Console\Command;
use Throwable;

class PickProfilePhoto extends Command
{
    protected $signature = 'dovira:pick-photo
        {profile : ID профілю}
        {--dry-run : Показати обране фото без збереження}';

    protected $description = 'Обирає портрет спеціаліста з його сайту через vision-модель і ставить як фото профілю.';

    public function handle(PortraitPickerService $picker): int
    {
        $profile = Profile::find((int) $this->argument('profile'));
        if (! $profile) {
            $this->error('Профіль не знайдено.');

            return self::FAILURE;
        }
        if (blank($profile->website)) {
            $this->error('У профілю нема website — нема звідки брати фото.');

            return self::FAILURE;
        }

        try {
            $result = $picker->pickFromWebsite((string) $profile->website, (string) $profile->name);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result === null) {
            $this->warn('Портрет не знайдено на сайті.');

            return self::SUCCESS;
        }

        $this->info('Обрано: '.$result['url']);
        $this->line('Причина: '.$result['reason']);

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $profile->forceFill(['logo_url' => $result['url']])->save();
        $this->info('Фото профілю оновлено.');

        return self::SUCCESS;
    }
}
