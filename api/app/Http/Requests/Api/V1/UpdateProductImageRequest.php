<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('image')->product) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'alt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_primary' => ['sometimes', 'boolean', 'accepted'],
            'variant_id' => ['sometimes', 'nullable', 'integer', Rule::exists('product_variants', 'id')->where('product_id', $this->route('image')->product_id)->whereNull('deleted_at')],
        ];
    }
}
