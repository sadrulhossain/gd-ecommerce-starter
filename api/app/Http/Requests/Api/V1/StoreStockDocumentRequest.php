<?php

namespace App\Http\Requests\Api\V1;

use App\Models\StockDocument;
use Illuminate\Foundation\Http\FormRequest;

class StoreStockDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [StockDocument::class, (string) $this->route('type')]) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return StockDocumentRules::rules((string) $this->route('type'), false);
    }
}
