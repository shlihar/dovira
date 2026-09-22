<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Support\CategoryHierarchy;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Вивантажує профілі, у яких БРАКУЄ контактів (email / соцмережі / сайт),
 * у JSONL-пакети для зовнішнього пошуку через ChatGPT/Deep Research — той самий
 * конвеєр, що й досьє та email (експорт → генерація в чаті → dovira:import-contacts).
 * На відміну від dovira:export-email-candidates збирає ВСІ контакти за раз, а не
 * лише пошту. Поруч кладе PROMPT.txt — вставляй у ChatGPT разом із вмістом пакета.
 *
 * Приклад: dovira:export-contact-candidates --only-dossier --chunk=50
 */
class ExportContactCandidates extends Command
{
    protected $signature = 'dovira:export-contact-candidates
        {--category=Адвокати : Назва категорії}
        {--only-dossier : Лише профілі з досьє (пріоритетний сегмент)}
        {--require-hook : Лише профілі із зачіпкою для пошуку (сайт, досьє або відгуки)}
        {--limit=0 : Скільки профілів максимум (0 — всі)}
        {--chunk=50 : Розмір пакета}';

    protected $description = 'Вивантажує профілі без повних контактів у JSONL-пакети для пошуку email/соцмереж через ChatGPT';

    public function handle(): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $chunk = max(1, (int) $this->option('chunk'));

        $ids = CategoryHierarchy::categoryIdsFromNames([(string) $this->option('category')]);
        if (empty($ids)) {
            $this->error('Категорію не знайдено.');

            return self::FAILURE;
        }

        // «Порожні» соцмережі — NULL, '' або '[]' (JSON без ключів).
        $noSocial = fn ($q) => $q->whereNull('social_links')
            ->orWhere('social_links', '')
            ->orWhere('social_links', '[]');
        $noEmail = fn ($q) => $q->whereNull('email')->orWhere('email', '');

        $query = Profile::query()
            ->where('status', 'active')
            ->whereNull('owner_user_id')
            ->where(fn ($q) => $q->where($noEmail)->orWhere($noSocial))
            ->whereIn('id', fn ($q) => $q->select('profile_id')->from('profile_category')->whereIn('category_id', $ids))
            ->when($this->option('only-dossier'), fn ($q) => $q->whereNotNull('dossier'))
            ->when($this->option('require-hook'), fn ($q) => $q->where(function ($w): void {
                $w->where(fn ($s) => $s->whereNotNull('website')->where('website', '!=', ''))
                    ->orWhereNotNull('dossier')
                    ->orWhere('reviews_count', '>', 0);
            }))
            // Спершу ті, де більше зачіпок для пошуку (сайт, відгуки).
            ->orderByRaw("case when website is not null and website != '' then 0 else 1 end")
            ->orderByDesc('reviews_count')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $profiles = $query->get([
            'id', 'name', 'city', 'district', 'address', 'phone', 'website', 'email', 'social_links', 'type',
        ]);

        if ($profiles->isEmpty()) {
            $this->info('Кандидатів немає — контакти вже заповнені.');

            return self::SUCCESS;
        }

        $dir = storage_path('app/contact-batches');
        @mkdir($dir, 0775, true);
        $stamp = now()->format('Ymd-His') . '-' . Str::lower(Str::random(4));

        $files = 0;
        foreach ($profiles->chunk($chunk) as $i => $batch) {
            $path = sprintf('%s/contact-batch-%s-%03d.jsonl', $dir, $stamp, $i + 1);
            $fh = fopen($path, 'w');

            foreach ($batch as $p) {
                $social = array_keys(array_filter((array) ($p->social_links ?? [])));

                // «need» — що саме шукати; «known» — що вже є (щоб чат не дублював роботу).
                fwrite($fh, json_encode([
                    'lawyer_id' => $p->id,
                    'full_name' => $p->name,
                    'city' => $p->city,
                    'district' => $p->district ?: null,
                    'address' => $p->address ?: null,
                    'phone' => $p->phone ?: null,
                    'known_website' => $p->website ?: null,
                    'known_email' => $p->email ?: null,
                    'known_socials' => $social ?: null,
                    'need' => array_values(array_filter([
                        blank($p->email) ? 'email' : null,
                        empty($social) ? 'socials' : null,
                        blank($p->website) ? 'website' : null,
                    ])),
                    'type' => $p->type,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            }

            fclose($fh);
            $files++;
            $this->line(sprintf('%s — %d профілів', basename($path), $batch->count()));
        }

        file_put_contents($dir . '/PROMPT.txt', $this->prompt());

        $this->info("Готово: {$profiles->count()} профілів у {$files} файлах → {$dir}");
        $this->line('Далі: ChatGPT → встав PROMPT.txt + вміст одного пакета → результат збережи у файл →');
        $this->line('php artisan dovira:import-contacts <файл> --dry-run');

        return self::SUCCESS;
    }

    private function prompt(): string
    {
        return <<<'TXT'
Ти — асистент з пошуку ПУБЛІЧНИХ контактів. Нижче JSONL-список українських адвокатів
та юридичних компаній. У кожному рядку: lawyer_id, імʼя (full_name), місто, район,
адреса, телефон, а також уже відомі контакти (known_website / known_email /
known_socials) і поле need — перелік того, що треба знайти для цього запису.

ЗАВДАННЯ: для кожного рядка знайди через веб-пошук те, що зазначено в need:
  • email        — публічна контактна пошта саме цієї особи/компанії;
  • socials      — офіційні сторінки: facebook, instagram, telegram, linkedin,
                   youtube, viber, whatsapp (лише ті, що знайшов; не всі обовʼязково);
  • website      — офіційний сайт.
Що вже є в known_* — НЕ шукай повторно, лишай як є (можеш скопіювати).

ДЕ ШУКАТИ: за «ПІБ місто адвокат», за телефоном, за відомим сайтом (сторінка
«Контакти»), у каталогах (uadvokat, jurliga, protocol.ua, ratabo), на бізнес-сторінках
Facebook/Instagram, у Google Maps, LinkedIn.

ПРАВИЛА (найважливіше):
- Повертай контакт ЛИШЕ якщо впевнений, що він належить саме цій особі/компанії
  (збіг імені / телефону / міста / сайту). Сумніваєшся — став null або пропусти ключ.
- НЕ ВИГАДУЙ адрес, посилань чи email. Краще null, ніж хибний контакт.
- URL соцмереж — повні (https://…), сторінка САМЕ цього адвоката/фірми, не загальна.
- source_url — сторінка, де знайшов основний контакт (для перевірки).

ФОРМАТ ВІДПОВІДІ — ЛИШЕ JSONL, по одному рядку на кожен lawyer_id, без пояснень,
без markdown-огортання. Ключі socials-мереж, яких не знайшов, просто НЕ додавай:
{"lawyer_id": 123, "email": "adv@example.com", "website": "https://advokat.ua", "socials": {"facebook": "https://facebook.com/advokat", "telegram": "https://t.me/advokat"}, "source_url": "https://advokat.ua/contacts"}
{"lawyer_id": 124, "email": null, "website": null, "socials": {}, "source_url": null}
TXT;
    }
}
