<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** A draft's header and, when `lines` is sent, its whole line list (replaced). */
class UpdateStockDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('stock_document')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return StockDocumentRules::rules((string) $this->route('type'), true);
    }
}
