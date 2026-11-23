<?php

namespace App\Http\Requests\Api\V1;

use App\Services\PurchaseOrderService;

/** Field rules for goods receipt lines (`lines.*.`). Ownership and outstanding quantity are checked by GoodsReceiptService. */
final class GoodsReceiptLineRules
{
    /** @return array<string, mixed> */
    public static function rules(string $prefix): array
    {
        return [
            "{$prefix}purchase_order_line_id" => ['required_with:lines', 'integer', 'distinct', 'exists:purchase_order_lines,id'],
            "{$prefix}quantity" => ['required_with:lines', 'integer', 'min:1', 'max:'.PurchaseOrderService::MAX_QUANTITY],
            "{$prefix}unit_cost" => ['nullable', 'integer', 'min:0', 'max:'.PurchaseOrderService::MAX_UNIT_COST],
        ];
    }
}
