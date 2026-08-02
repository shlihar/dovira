<?php

namespace App\Http\Requests;

use App\Models\Profile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProfileClaimRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'profile_id' => [
                'required',
                'integer',
                Rule::exists(Profile::class, 'id')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'company_role' => ['nullable', 'string', 'max:120'],
            'proof_document_url' => ['nullable', 'url', 'max:2048'],
            'note' => ['nullable', 'string', 'max:3000'],
        ];
    }
}
