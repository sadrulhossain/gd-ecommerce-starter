<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Product fields only: variants, images and `status` have their own endpoints. */
class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('product')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'required', 'integer', Rule::exists('product_categories', 'id')],
            'brand_id' => ['sometimes', 'nullable', 'integer', Rule::exists('brands', 'id')],
            'tax_rate_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tax_rates', 'id')],
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'slug' => [
                'sometimes', 'required', 'string', 'max:220', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('products', 'slug')->ignore($this->route('product')),
            ],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
