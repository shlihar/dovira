<?php

namespace App\Filament\Resources\ProfileLeads\Pages;

use App\Filament\Resources\ProfileLeads\ProfileLeadResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewProfileLead extends ViewRecord
{
    protected static string $resource = ProfileLeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('mark_read')
                ->label('Позначити прочитаною')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => ! (bool) $this->record->is_read)
                ->action(fn (): bool => $this->record->update(['is_read' => true])),
            Action::make('mark_unread')
                ->label('Позначити непрочитаною')
                ->icon('heroicon-o-envelope')
                ->visible(fn (): bool => (bool) $this->record->is_read)
                ->action(fn (): bool => $this->record->update(['is_read' => false])),
            Action::make('open_profile')
                ->label('Відкрити профіль')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn () => route('filament.admin.resources.profiles.edit', ['record' => $this->record->profile_id])),
        ];
    }
}
