<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseOrderLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('line')->purchaseOrder) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return PurchaseOrderLineRules::rules('', true);
    }
}
