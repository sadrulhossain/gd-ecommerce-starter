<?php

namespace App\Http\Requests\Api\V1;

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', GoodsReceipt::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'purchase_order_id' => ['required', 'integer', Rule::exists('purchase_orders', 'id')->whereIn('status', [PurchaseOrder::ORDERED, PurchaseOrder::PARTIALLY_RECEIVED])],
            'delivery_note' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['sometimes', 'array', 'max:200'],
            ...GoodsReceiptLineRules::rules('lines.*.'),
        ];
    }
}
