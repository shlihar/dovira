<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\OverviewStatsWidget;
use App\Filament\Widgets\ProfilesProTrendChart;
use App\Filament\Widgets\QuickActionsWidget;
use App\Filament\Widgets\ReviewsUsersTrendChart;
use App\Filament\Widgets\SiteTrafficSourcesTrendChart;
use App\Filament\Widgets\SiteViewsSourcesWidget;
use App\Filament\Support\AnalyticsFiltersSchema;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public static function getNavigationLabel(): string
    {
        return 'Dashboard';
    }

    public function getColumns(): int | array
    {
        return [
            'md' => 2,
            'xl' => 12,
        ];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            AnalyticsFiltersSchema::make(),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            QuickActionsWidget::class,
            OverviewStatsWidget::class,
            SiteViewsSourcesWidget::class,
            ReviewsUsersTrendChart::class,
            ProfilesProTrendChart::class,
            SiteTrafficSourcesTrendChart::class,
        ];
    }
}
