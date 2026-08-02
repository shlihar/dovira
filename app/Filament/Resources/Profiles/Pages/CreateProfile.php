<?php

namespace App\Filament\Resources\Profiles\Pages;

use App\Filament\Resources\Profiles\ProfileResource;
use App\Support\CategoryHierarchy;
use Illuminate\Support\Facades\Auth;
use Filament\Resources\Pages\CreateRecord;

class CreateProfile extends CreateRecord
{
    protected static string $resource = ProfileResource::class;

    protected ?int $categoryId = null;

    protected ?int $subcategoryId = null;

    /**
     * @var array<int, int>
     */
    protected array $serviceIds = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->categoryId = isset($data['category_id']) ? (int) $data['category_id'] : null;
        $this->subcategoryId = isset($data['subcategory_id']) ? (int) $data['subcategory_id'] : null;
        $this->serviceIds = collect($data['service_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values()->all();

        unset($data['category_id'], $data['subcategory_id'], $data['service_ids']);

        if (blank($data['type'] ?? null)) {
            $data['type'] = 'company';
        }

        $status = (string) ($data['dovira_recommendation_status'] ?? '');
        if (! in_array($status, ['recommend', 'not_recommend'], true)) {
            $data['dovira_recommendation_status'] = null;
        }

        $data['created_by_user_id'] = Auth::id();
        $data['updated_by_user_id'] = Auth::id();

        return $data;
    }

    protected function afterCreate(): void
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
