<?php

namespace App\Filament\Resources\Profiles\Pages;

use App\Filament\Resources\Profiles\ProfileResource;
use App\Filament\Resources\Top20ImportBatches\Top20ImportBatchResource;
use App\Jobs\RefreshProfileFromTop20;
use App\Jobs\RefreshProfileReviewAiAnalysis;
use App\Models\Top20ImportBatch;
use App\Support\CategoryHierarchy;
use App\Services\AiProfileEnrichmentService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditProfile extends EditRecord
{
    protected static string $resource = ProfileResource::class;

    protected ?int $categoryId = null;

    protected ?int $subcategoryId = null;

    protected ?int $previousOwnerId = null;

    /**
     * @var array<int, int>
     */
    protected array $serviceIds = [];

    /**
     * Кнопка «Зберегти» ще й під формою: на телефоні після правки кейсів
     * інакше треба мотати всю сторінку вгору до шапки.
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()->label('Зберегти'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Зберегти')
                ->icon('heroicon-o-check')
                ->color('primary')
                ->action('save'),
            Action::make('public')
                ->label('Публічна сторінка')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn () => route('profile.show', ['slug' => $this->record->slug]))
                ->openUrlInNewTab(),
            // Швидкі посилання для підтримки/продажів: оплата, підтвердження
            // прав (signed, 30 днів), збір відгуків + QR. Все з копіюванням.
            Action::make('quick_links')
                ->label('Посилання')
                ->icon('heroicon-o-link')
                ->color('gray')
                ->modalHeading(fn () => 'Посилання · ' . $this->record->name)
                ->modalSubmitAction(false)
                ->modalContent(function () {
                    // Посилання завжди на публічний домен: адмінку можуть
                    // відкривати локально, а лінки йдуть клієнтам. Signed-підпис
                    // рахується від повного URL, тому домен важливий і для нього.
                    $publicBase = rtrim((string) config('outreach.public_url', 'https://mydovira.com'), '/');
                    \Illuminate\Support\Facades\URL::forceRootUrl($publicBase);
                    if (str_starts_with($publicBase, 'https://')) {
                        // Signed-підпис рахується від повного URL — без https
                        // клієнт отримав би 403 на валідації підпису.
                        \Illuminate\Support\Facades\URL::forceScheme('https');
                    }

                    $links = [
                        'pay' => route('pro.account.billing.pay', ['profile' => $this->record->id]),
                        'claim' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                            'profile.claim-link',
                            now()->addDays(30),
                            ['profile' => $this->record->id]
                        ),
                        'review' => route('profile.review-link', ['slug' => $this->record->slug]),
                    ];

                    \Illuminate\Support\Facades\URL::forceRootUrl((string) config('app.url'));

                    // Готові повідомлення клієнту — з реальною ціною (знижка
                    // за бейдж врахована) і поштою акаунта власника для входу.
                    $discount = \App\Support\ProDiscount::price($this->record, \App\Support\ProPricing::DISPLAY_CURRENT);
                    $priceLine = ! empty($discount['has_discount'])
                        ? number_format((int) $discount['amount'], 0, '', ' ') . ' грн за 6 місяців (з урахуванням знижки −' . (int) $discount['discount_percent'] . '% за розміщення бейджа; базова ціна ' . number_format((int) $discount['base_amount'], 0, '', ' ') . ' грн)'
                        : number_format((int) $discount['amount'], 0, '', ' ') . ' грн за 6 місяців';
                    $ownerEmail = $this->record->owner_user_id
                        ? (string) \App\Models\User::query()->whereKey($this->record->owner_user_id)->value('email')
                        : '';

                    $messages = [
                        'pay' => "Доброго дня! Ваш профіль «{$this->record->name}» на Dovira підтверджено — можна активувати PRO: керування сторінкою, відповіді на відгуки, контакти та аналітика.\n\n"
                            . "Посилання на оплату: {$links['pay']}\n"
                            . "Вартість: {$priceLine}.\n\n"
                            . ($ownerEmail !== '' ? "Якщо сайт попросить увійти — використайте пошту {$ownerEmail}. " : '')
                            . 'Якщо виникнуть питання — просто відповідайте на цей лист.',
                        'claim' => "Доброго дня! Сторінка «{$this->record->name}» вже опублікована на Dovira: клієнти бачать її на платформі та знаходять у Google.\n\n"
                            . "Підтвердіть права на профіль безкоштовно — одне натискання за посиланням (без реєстрації, діє 30 днів):\n{$links['claim']}\n\n"
                            . 'Після підтвердження відкриється кабінет зі статистикою переглядів вашої сторінки.',
                        'review' => "Дякуємо, що звернулися до «{$this->record->name}»! Будемо вдячні за короткий відгук про нашу роботу — це займе близько хвилини:\n{$links['review']}",
                    ];

                    return view('filament.profiles.quick-links', [
                        'links' => $links,
                        'messages' => $messages,
                        'profile' => $this->record,
                    ]);
                }),
            // Рідковживані дії — під «Ще»: у шапці на телефоні сім кнопок
            // займали цілий екран до початку форми.
            \Filament\Actions\ActionGroup::make([
            Action::make('refresh_ai_review_analysis')
                ->label('Оновити AI-аналіз відгуків')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->action(function (): void {
                    RefreshProfileReviewAiAnalysis::dispatch((int) $this->record->id)->afterCommit();

                    Notification::make()
                        ->title('AI-аналіз відгуків поставлено в чергу')
                        ->success()
                        ->send();
                }),
            Action::make('refresh_top20_import')
                ->label('Оновити імпорт з Top20')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Повторно завантажить дані профілю та відгуки з пов’язаного Top20-джерела саме для цього профілю.')
                ->visible(fn () => $this->record->dataSources()->whereIn('source_type', ['top20_import', 'top20'])->whereNotNull('url')->exists()
                    || $this->record->top20ImportItems()->whereNotNull('top20_url')->exists())
                ->action(function (): void {
                    $top20Url = $this->record->dataSources()
                        ->whereIn('source_type', ['top20_import', 'top20'])
                        ->whereNotNull('url')
                        ->orderByDesc('id')
                        ->value('url')
                        ?: $this->record->top20ImportItems()
                            ->whereNotNull('top20_url')
                            ->orderByDesc('id')
                            ->value('top20_url');

                    if (! $top20Url) {
                        Notification::make()
                            ->title('Для цього профілю не знайдено Top20-джерело')
                            ->danger()
                            ->send();

                        return;
                    }

                    $selection = CategoryHierarchy::selectionFromProfile($this->record);
                    $batch = Top20ImportBatch::query()->create([
                        'user_id' => Auth::id(),
                        'category_id' => $selection['category_id'],
                        'subcategory_id' => $selection['subcategory_id'],
                        'name' => sprintf(
                            'Оновлення профілю · %s · %s',
                            $this->record->name,
                            now()->format('d.m.Y H:i')
                        ),
                        'status' => 'processing',
                        'city_name' => $this->record->city,
                        'listing_url' => (string) $top20Url,
                        'options' => [
                            'mode' => 'profile_refresh',
                            'profile_id' => (int) $this->record->id,
                            'refresh_existing' => true,
                            'publish_profiles' => true,
                        ],
                        'started_at' => now(),
                    ]);

                    $item = $batch->items()->create([
                        'profile_id' => (int) $this->record->id,
                        'category_id' => $selection['category_id'],
                        'subcategory_id' => $selection['subcategory_id'],
                        'status' => 'processing',
                        'raw_name' => (string) $this->record->name,
                        'raw_city' => (string) ($this->record->city ?? ''),
                        'top20_url' => (string) $top20Url,
                        'raw_payload' => [
                            'mode' => 'profile_refresh',
                        ],
                    ]);

                    RefreshProfileFromTop20::dispatch(
                        (int) $this->record->id,
                        Auth::id(),
                        (int) $batch->id,
                        (int) $item->id,
                        true
                    )->afterCommit();

                    Notification::make()
                        ->title('Оновлення Top20 поставлено в чергу')
                        ->body('Профіль і відгуки буде оновлено у фоновому режимі.')
                        ->actions([
                            Action::make('open_import')
                                ->label('Відкрити імпорт')
                                ->url(Top20ImportBatchResource::getUrl('edit', ['record' => $batch])),
                        ])
                        ->success()
                        ->send();
                }),
            Action::make('refresh_ai_enrichment')
                ->label('Оновити AI-збагачення')
                ->icon('heroicon-o-bolt')
                ->color('info')
                ->requiresConfirmation()
                ->modalDescription('Запустить AI-збагачення саме для цього профілю та оновить знайдені дані, джерела і зовнішні згадки.')
                ->action(function (AiProfileEnrichmentService $aiProfileEnrichmentService): void {
                    $task = $aiProfileEnrichmentService->refreshSingleProfile($this->record, Auth::id());

                    Notification::make()
                        ->title('AI-збагачення профілю оновлено')
                        ->body("Статус задачі: {$task->status}")
                        ->success()
                        ->send();
                }),
            Action::make('archive')
                ->label('Архівувати')
                ->icon('heroicon-o-archive-box')
                ->color('warning')
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update([
                        'status' => 'archived',
                        'show_in_catalog' => false,
                        'updated_by_user_id' => Auth::id(),
                    ]);

                    Notification::make()->title('Профіль архівовано')->success()->send();
                }),
            DeleteAction::make()
                ->label('Видалити')
                ->requiresConfirmation(),
            ])
                ->label('Ще')
                ->icon('heroicon-o-ellipsis-horizontal')
                ->button()
                ->color('gray'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $selection = CategoryHierarchy::selectionFromProfile($this->record);
        $data['category_id'] = $selection['category_id'];
        $data['subcategory_id'] = $selection['subcategory_id'];

        $data['service_ids'] = $this->record->services()->pluck('category_services.id')->map(fn ($id) => (int) $id)->all();

        // Кейси лежать у JSON (ai_suggested_data.owner_profile.cases), тож
        // підставляємо їх у власне поле форми, а не редагуємо колонку напряму:
        // інакше збереження затерло б решту ключів ai_suggested_data.
        $data['owner_cases'] = collect(\App\Support\ProfileCases::normalize(
            data_get($this->record->ai_suggested_data, 'owner_profile.cases', [])
        ))->map(function (array $case): array {
            // Завантажений файл і зовнішнє посилання живуть в одному полі
            // `image`, але в формі це різні контроли.
            $image = (string) ($case['image'] ?? '');
            $case['image'] = str_starts_with($image, 'http') ? null : ($image ?: null);
            $case['image_url'] = str_starts_with($image, 'http') ? $image : null;

            return $case;
        })->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->categoryId = isset($data['category_id']) ? (int) $data['category_id'] : null;
        $this->subcategoryId = isset($data['subcategory_id']) ? (int) $data['subcategory_id'] : null;
        $this->serviceIds = collect($data['service_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values()->all();

        // Запамʼятовуємо власника ДО збереження, щоб у afterSave помітити ручну
        // привʼязку і надіслати сповіщення (власнику + адміну в Telegram).
        $this->previousOwnerId = $this->record->owner_user_id ? (int) $this->record->owner_user_id : null;

        unset($data['category_id'], $data['subcategory_id'], $data['service_ids']);

        if (blank($data['type'] ?? null)) {
            $data['type'] = $this->record->type ?: 'company';
        }

        if (($data['status'] ?? null) === 'active') {
            $data['is_published'] = true;
            $data['show_in_catalog'] = true;
        }

        // Ручна правка досьє в адмінці → джерело «admin» (для бейджа на сторінці).
        if (array_key_exists('dossier', $data) && trim((string) $data['dossier']) !== trim((string) $this->record->dossier)) {
            $data['dossier_source'] = trim((string) $data['dossier']) === '' ? null : 'admin';
            $data['dossier_generated_at'] = now();
        }

        if (array_key_exists('dovira_recommendation_status', $data)) {
            $status = (string) ($data['dovira_recommendation_status'] ?? '');
            $data['dovira_recommendation_status'] = in_array($status, ['recommend', 'not_recommend'], true)
                ? $status
                : null;
        } else {
            $data['dovira_recommendation_status'] = $this->record->dovira_recommendation_status;
        }

        // Keep current media paths when FileUpload returns an empty value during metadata-only updates.
        if (array_key_exists('logo_url', $data) && blank($data['logo_url'])) {
            $data['logo_url'] = $this->record->logo_url;
        }
        if (array_key_exists('banner_url', $data) && blank($data['banner_url'])) {
            $data['banner_url'] = $this->record->banner_url;
        }
        if (array_key_exists('og_image_url', $data) && blank($data['og_image_url'])) {
            $data['og_image_url'] = $this->record->og_image_url;
        }

        // Кейси: складаємо назад у JSON, не чіпаючи інші ключі ai_suggested_data.
        if (array_key_exists('owner_cases', $data)) {
            $cases = collect((array) $data['owner_cases'])
                ->map(function ($case): array {
                    $case = (array) $case;
                    $case['image'] = filled($case['image'] ?? null)
                        ? (string) $case['image']
                        : (string) ($case['image_url'] ?? '');
                    unset($case['image_url']);

                    return $case;
                })
                ->all();

            $aiData = (array) ($this->record->ai_suggested_data ?? []);
            $ownerProfile = is_array($aiData['owner_profile'] ?? null) ? $aiData['owner_profile'] : [];
            $ownerProfile['cases'] = \App\Support\ProfileCases::normalize($cases);
            $aiData['owner_profile'] = $ownerProfile;
            $data['ai_suggested_data'] = $aiData;

            unset($data['owner_cases']);
        }

        $data['updated_by_user_id'] = Auth::id();

        return $data;
    }

    protected function afterSave(): void
    {
        CategoryHierarchy::syncProfileCategories($this->record, $this->categoryId, $this->subcategoryId);

        $allowedServiceIds = [];
        if ($this->categoryId) {
            $allowedServiceIds = \App\Models\CategoryService::query()
                ->where('category_id', $this->categoryId)
                ->whereIn('id', $this->serviceIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $this->record->services()->sync($allowedServiceIds);

        // Власника призначили/змінили вручну через форму → шлемо ті самі
        // сповіщення, що й при підтвердженні заявки (in-app + email власнику,
        // Telegram адміну, слід у «Заявках»).
        $newOwnerId = $this->record->owner_user_id ? (int) $this->record->owner_user_id : null;
        if ($newOwnerId !== null && $newOwnerId !== $this->previousOwnerId) {
            $owner = \App\Models\User::query()->find($newOwnerId);
            if ($owner) {
                app(\App\Services\ProfileClaimReviewService::class)->linkOwnerManually($this->record, $owner);

                Notification::make()
                    ->title('Власника привʼязано — сповіщення надіслано')
                    ->success()
                    ->send();
            }
        }
    }
}
