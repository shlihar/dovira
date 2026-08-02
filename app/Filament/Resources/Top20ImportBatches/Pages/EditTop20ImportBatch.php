<?php

namespace App\Filament\Resources\Top20ImportBatches\Pages;

use App\Filament\Resources\Top20ImportBatches\Top20ImportBatchResource;
use App\Models\Top20ImportBatch;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditTop20ImportBatch extends EditRecord
{
    protected static string $resource = Top20ImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish_all_profiles')
                ->label('Опублікувати всі')
                ->icon('heroicon-o-eye')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Опублікує всі профілі з цього імпорту, які вже були створені, але ще не опубліковані.')
                ->visible(fn (Top20ImportBatch $record): bool => $record->items()->whereNotNull('profile_id')->exists())
                ->action(function (Top20ImportBatch $record): void {
                    $published = 0;

                    $record->items()
                        ->with('profile')
                        ->whereNotNull('profile_id')
                        ->get()
                        ->each(function ($item) use (&$published): void {
                            $profile = $item->profile;
                            if (! $profile) {
                                return;
                            }

                            if ($profile->status === 'active' && $profile->is_published && $profile->show_in_catalog) {
                                return;
                            }

                            $profile->update([
                                'status' => 'active',
                                'is_published' => true,
                                'show_in_catalog' => true,
                            ]);
                            $published++;
                        });

                    Notification::make()
                        ->title("Опубліковано профілів: {$published}")
                        ->success()
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }
}
