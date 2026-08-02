<?php

namespace App\Livewire\Filament;

use App\Models\Category;
use Livewire\Component;

class CategoryAnalyticsFilters extends Component
{
    public int $recordId = 0;
    public ?Category $record = null;
    public string $period = 'last_7';
    public ?string $startDate = null;
    public ?string $endDate = null;

    private bool $isBooting = true;

    /**
     * @var array<int, string>
     */
    private const ALLOWED_PERIODS = [
        'today',
        'last_7',
        'last_30',
        'last_90',
        'all_time',
        'custom',
    ];

    public function mount(mixed $recordId = null): void
    {
        $resolvedRecordId = is_object($recordId) && method_exists($recordId, 'getKey')
            ? (int) $recordId->getKey()
            : (int) $recordId;

        $this->recordId = $resolvedRecordId;
        $this->record = Category::query()->find($resolvedRecordId);
        $this->period = 'last_7';
        $this->startDate = null;
        $this->endDate = null;

        $this->isBooting = false;
        $this->applyFilters();
    }

    public function render()
    {
        return view('livewire.filament.category-analytics-filters');
    }

    public function updatedPeriod(): void
    {
        if ($this->isBooting) {
            return;
        }

        if ($this->period !== 'custom') {
            $this->startDate = null;
            $this->endDate = null;
        }

        $this->applyFilters();
    }

    public function updatedStartDate(): void
    {
        if ($this->isBooting) {
            return;
        }

        if (! empty($this->startDate) || ! empty($this->endDate)) {
            $this->period = 'custom';
        }

        $this->applyFilters();
    }

    public function updatedEndDate(): void
    {
        if ($this->isBooting) {
            return;
        }

        if (! empty($this->startDate) || ! empty($this->endDate)) {
            $this->period = 'custom';
        }

        $this->applyFilters();
    }

    private function applyFilters(): void
    {
        $this->period = $this->normalizePeriod($this->period);

        if ($this->period !== 'custom') {
            $this->startDate = null;
            $this->endDate = null;
        }
    }

    private function normalizePeriod(string $period): string
    {
        $legacyMap = [
            '7' => 'last_7',
            '30' => 'last_30',
            '90' => 'last_90',
        ];

        $period = $legacyMap[$period] ?? $period;

        return in_array($period, self::ALLOWED_PERIODS, true) ? $period : 'last_7';
    }
}
