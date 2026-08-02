<?php

namespace App\Filament\Resources\Profiles\Widgets;

use App\Models\Profile;
use Filament\Widgets\Widget;

class ProfileAnalyticsFiltersWidget extends Widget
{
    protected string $view = 'filament.resources.profiles.widgets.profile-analytics-filters-widget';

    protected int | string | array $columnSpan = 'full';

    public ?Profile $record = null;

    protected function getViewData(): array
    {
        return [
            'period' => request()->query('analytics_period', 'last_7'),
            'from' => request()->query('analytics_from'),
            'to' => request()->query('analytics_to'),
        ];
    }
}
