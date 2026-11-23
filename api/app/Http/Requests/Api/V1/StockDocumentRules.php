<?php

namespace App\Http\Requests\Api\V1;

use App\Models\StockDocument;
use App\Services\PurchaseOrderService;
use App\Services\StockDocumentService;
use Illuminate\Validation\Rule;

/** Validation rules shared by the create and update requests of all four stock document types. */
final class StockDocumentRules
{
    /** @return array<string, mixed> */
    public static function rules(string $type, bool $partial): array
    {
        $max = StockDocumentService::MAX_QUANTITY;
        $quantity = match ($type) {
            StockDocument::ADJUSTMENT => ['not_in:0', "min:-{$max}", "max:{$max}"],
            StockDocument::COUNT => ['min:0', "max:{$max}"],
            default => ['min:1', "max:{$max}"],
        };
        $some = $partial ? ['sometimes'] : [];

        return [
            'reason' => match ($type) {
                StockDocument::DAMAGE, StockDocument::ADJUSTMENT => [...$some, 'required', 'string', 'max:200'],
                StockDocument::RETURN => [...$some, 'nullable', 'string', 'max:200'],
                default => ['prohibited'],
            },
            'supplier_id' => $type === StockDocument::RETURN
                ? [...$some, 'required', 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)->whereNull('deleted_at')]
                : ['prohibited'],
            'notes' => [...$some, 'nullable', 'string', 'max:5000'],
            'lines' => ['sometimes', 'array', 'max:200'],
            'lines.*.variant_id' => ['required_with:lines', 'integer', 'distinct', Rule::exists('product_variants', 'id')->whereNull('deleted_at')],
            'lines.*.quantity' => ['required_with:lines', 'integer', ...$quantity],
            'lines.*.unit_cost' => ['nullable', 'integer', 'min:0', 'max:'.PurchaseOrderService::MAX_UNIT_COST],
        ];
    }
}
