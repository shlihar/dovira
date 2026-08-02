<?php

namespace App\Services\AiEnrichment\Contracts;

use App\Models\AiEnrichmentBatch;
use App\Models\Category;

interface ProfileEnrichmentProvider
{
    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function enrich(AiEnrichmentBatch $batch, array $item, ?Category $category, ?string $city): array;
}
