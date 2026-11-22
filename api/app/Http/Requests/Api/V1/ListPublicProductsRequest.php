<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Query string of GET public/products. */
class ListPublicProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['sometimes', 'string', 'max:150'],
            'brand' => ['sometimes', 'string', 'max:150'],
            'q' => ['sometimes', 'string', 'max:100'],
            'min_price' => ['sometimes', 'integer', 'min:0'],
            'max_price' => ['sometimes', 'integer', 'min:0'],
            'sort' => ['sometimes', Rule::in(['newest', 'price_asc', 'price_desc', 'name'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:48'],
        ];
    }
}
