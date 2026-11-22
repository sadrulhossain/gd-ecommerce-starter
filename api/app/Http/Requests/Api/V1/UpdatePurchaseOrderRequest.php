<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Header fields of a draft order; lines have their own endpoints. */
class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('purchase_order')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'supplier_id' => ['sometimes', 'required', 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'expected_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
