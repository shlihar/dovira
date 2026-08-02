<?php

namespace App\Filament\Resources\ProfileClaims\Pages;

use App\Filament\Resources\ProfileClaims\ProfileClaimResource;
use App\Services\ProfileClaimReviewService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewProfileClaim extends ViewRecord
{
    protected static string $resource = ProfileClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open_profile')
                ->label('Профіль')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn () => route('filament.admin.resources.profiles.edit', ['record' => $this->record->profile_id])),
            Action::make('approve')
                ->label('Підтвердити')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->status !== 'approved')
                ->action(function (ProfileClaimReviewService $service): void {
                    $service->approve($this->record);

                    Notification::make()
                        ->title('Заявку підтверджено і профіль прив’язано')
                        ->success()
                        ->send();

                    $this->refreshFormData([
                        'status',
                        'reviewed_by_user_id',
                        'reviewed_at',
                    ]);
                }),
            Action::make('need_more_info')
                ->label('Потребує уточнення')
                ->icon('heroicon-o-information-circle')
                ->color('warning')
                ->visible(fn () => $this->record->status !== 'need_more_info')
                ->action(function (ProfileClaimReviewService $service): void {
                    $service->markNeedMoreInfo($this->record);

                    Notification::make()
                        ->title('Статус змінено: потребує уточнення')
                        ->warning()
                        ->send();

                    $this->refreshFormData([
                        'status',
                        'reviewed_by_user_id',
                        'reviewed_at',
                    ]);
                }),
            Action::make('reject')
                ->label('Відхилити')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->record->status !== 'rejected')
                ->action(function (ProfileClaimReviewService $service): void {
                    $service->reject($this->record);

                    Notification::make()
                        ->title('Заявку відхилено')
                        ->danger()
                        ->send();

                    $this->refreshFormData([
                        'status',
                        'reviewed_by_user_id',
                        'reviewed_at',
                    ]);
                }),
        ];
    }
}
