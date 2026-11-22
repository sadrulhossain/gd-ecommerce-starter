<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('purchase_order')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = PurchaseOrderLineRules::rules('', false);
        $rules['variant_id'][] = Rule::unique('purchase_order_lines', 'variant_id')->where('purchase_order_id', $this->route('purchase_order')->id);

        return $rules;
    }
}
