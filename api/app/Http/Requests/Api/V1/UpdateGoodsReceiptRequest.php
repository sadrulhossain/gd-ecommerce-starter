<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** A draft's header and, when `lines` is sent, its whole line list (replaced). */
class UpdateGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('goods_receipt')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'delivery_note' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'lines' => ['sometimes', 'array', 'max:200'],
            ...GoodsReceiptLineRules::rules('lines.*.'),
        ];
    }
}
