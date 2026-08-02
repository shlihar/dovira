<?php

namespace App\Filament\Resources\Profiles\Pages;

use App\Filament\Resources\Profiles\ProfileResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProfile extends ViewRecord
{
    protected static string $resource = ProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('public')
                ->label('Публічна сторінка')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn () => route('profile.show', ['slug' => $this->record->slug]))
                ->openUrlInNewTab(),
            EditAction::make(),
        ];
    }
}
