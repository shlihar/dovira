<?php

namespace App\Filament\Resources\AiEnrichmentBatches\Pages;

use App\Filament\Resources\AiEnrichmentBatches\AiEnrichmentBatchResource;
use App\Jobs\ProcessAiEnrichmentBatch;
use App\Services\Queue\AiEnrichmentQueueWorkerManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAiEnrichmentBatch extends EditRecord
{
    protected static string $resource = AiEnrichmentBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('process')
                ->label('Запустити повторно')
                ->icon('heroicon-o-play')
                ->color('primary')
                ->requiresConfirmation()
                ->visible(fn () => ! in_array($this->record->status, ['queued', 'processing'], true))
                ->action(function (): void {
                    $this->record->update(['status' => 'queued']);

                    ProcessAiEnrichmentBatch::dispatchAndEnsureWorker($this->record->id);
                    $workers = count(app(AiEnrichmentQueueWorkerManager::class)->runningWorkerPids());

                    Notification::make()
                        ->title('AI-збагачення поставлено в повторну обробку')
                        ->body($workers > 0
                            ? "Активних worker: {$workers}."
                            : 'Worker не запущений. Запустіть composer dev або php artisan dovira:ai-enrichment:work.')
                        ->success()
                        ->send();
                }),
            Action::make('cancel')
                ->label('Зупинити')
                ->icon('heroicon-o-stop')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn () => in_array($this->record->status, ['queued', 'processing'], true))
                ->action(function (): void {
                    app(AiEnrichmentQueueWorkerManager::class)->stopAllWorkers(true);

                    $this->record->tasks()
                        ->whereIn('status', ['pending', 'processing'])
                        ->update([
                            'status' => 'rejected',
                            'error_message' => 'Зупинено адміністратором.',
                            'processed_at' => now(),
                        ]);

                    $this->record->update([
                        'status' => 'cancelled',
                        'finished_at' => now(),
                    ]);

                    Notification::make()
                        ->title('AI-збагачення зупинено')
                        ->warning()
                        ->send();
                }),
            Action::make('kick_queue')
                ->label('Продовжити')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => in_array($this->record->status, ['queued', 'processing'], true))
                ->action(function (): void {
                    $manager = app(AiEnrichmentQueueWorkerManager::class);
                    $result = $manager->restartWorkerAndRecoverQueue();
                    $manager->startManagedWorker();
                    $workers = count($manager->runningWorkerPids());

                    Notification::make()
                        ->title('Worker відновлено')
                        ->body("Зупинено: {$result['stopped_workers']}. Повернуто в чергу: {$result['released_jobs']}. Активних worker: {$workers}.")
                        ->success()
                        ->send();
                }),
        ];
    }
}
