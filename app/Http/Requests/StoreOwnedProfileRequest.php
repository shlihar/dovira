<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Region;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOwnedProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', Rule::exists(Category::class, 'id')->where(fn ($query) => $query->whereNull('parent_id')->where('is_active', true))],
            'subcategory_id' => ['nullable', 'integer', Rule::exists(Category::class, 'id')->where(fn ($query) => $query->where('is_active', true))],
            'city' => ['nullable', 'string', 'max:255'],
            'region_id' => ['nullable', 'integer', Rule::exists(Region::class, 'id')],
            'website' => ['nullable', 'url', 'max:2048'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
