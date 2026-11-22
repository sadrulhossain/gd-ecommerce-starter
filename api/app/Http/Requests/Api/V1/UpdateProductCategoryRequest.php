<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\ValidCategoryParent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('product_category')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $category = $this->route('product_category');

        return [
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('product_categories', 'id'), new ValidCategoryParent($category)],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'slug' => [
                'sometimes', 'required', 'string', 'max:150', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('product_categories', 'slug')->ignore($category),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
