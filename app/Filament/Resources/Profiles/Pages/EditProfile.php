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

    /**
     * @var array<int, int>
     */
    protected array $serviceIds = [];

    protected function getFormActions(): array
    {
        return [];
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
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $selection = CategoryHierarchy::selectionFromProfile($this->record);
        $data['category_id'] = $selection['category_id'];
        $data['subcategory_id'] = $selection['subcategory_id'];

        $data['service_ids'] = $this->record->services()->pluck('category_services.id')->map(fn ($id) => (int) $id)->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->categoryId = isset($data['category_id']) ? (int) $data['category_id'] : null;
        $this->subcategoryId = isset($data['subcategory_id']) ? (int) $data['subcategory_id'] : null;
        $this->serviceIds = collect($data['service_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values()->all();

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
    }
}
