<?php

namespace App\Http\Requests\Api\V1;

use App\Services\PurchaseOrderService;
use Illuminate\Validation\Rule;

/** Field rules for purchase order lines, inline (`lines.*.`) or one at a time. */
final class PurchaseOrderLineRules
{
    /** @return array<string, mixed> */
    public static function rules(string $prefix, bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $rules = [
            "{$prefix}quantity_ordered" => [...$required, 'integer', 'min:1', 'max:'.PurchaseOrderService::MAX_QUANTITY],
            "{$prefix}unit_cost" => $partial
                ? ['sometimes', 'integer', 'min:0', 'max:'.PurchaseOrderService::MAX_UNIT_COST]
                : ['nullable', 'integer', 'min:0', 'max:'.PurchaseOrderService::MAX_UNIT_COST],
            "{$prefix}tax_rate_bp" => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];

        if (! $partial) {
            $rules["{$prefix}variant_id"] = [
                'required', 'integer',
                ...(str_contains($prefix, '*') ? ['distinct'] : []),
                Rule::exists('product_variants', 'id')->whereNull('deleted_at'),
            ];
        }

        return $rules;
    }
}
