<?php

namespace App\Filament\Resources\AiEnrichmentBatches\Pages;

use App\Filament\Resources\AiEnrichmentBatches\AiEnrichmentBatchResource;
use App\Jobs\ProcessAiEnrichmentBatch;
use App\Services\Queue\AiEnrichmentQueueWorkerManager;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateAiEnrichmentBatch extends CreateRecord
{
    protected static string $resource = AiEnrichmentBatchResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = Auth::id();
        $data['status'] = 'draft';

        if (($data['source_type'] ?? null) === 'manual' && isset($data['input_text'])) {
            $raw = (string) $data['input_text'];
            $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
            $lines = array_map(function (string $line): string {
                // Normalize uncommon spaces and trim wrappers.
                $line = str_replace("\u{00A0}", ' ', $line);

                return trim($line);
            }, $lines);
            $lines = array_values(array_filter($lines, fn (string $line) => $line !== ''));
            $data['input_text'] = implode("\n", $lines);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->update(['status' => 'queued']);

        ProcessAiEnrichmentBatch::dispatchAndEnsureWorker($this->record->id);
        $workers = count(app(AiEnrichmentQueueWorkerManager::class)->runningWorkerPids());

        Notification::make()
            ->title('AI-збагачення поставлено в обробку')
            ->body($workers > 0
                ? "Черга створить чернетки, перевірить дублікати й збере джерела. Активних worker: {$workers}."
                : 'Задачу поставлено в чергу, але worker не запущений. Запустіть composer dev або php artisan dovira:ai-enrichment:work.')
            ->success()
            ->send();
    }
}
