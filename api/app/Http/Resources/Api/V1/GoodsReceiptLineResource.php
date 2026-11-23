<?php

namespace App\Http\Resources\Api\V1;

use App\Models\GoodsReceiptLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Money is `{amount, currency}` with `amount` in integer minor units.
 *
 * @mixin GoodsReceiptLine
 */
class GoodsReceiptLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_line_id' => $this->purchase_order_line_id,
            'variant_id' => $this->variant_id,
            'sku' => $this->sku,
            'name' => $this->name,
            'quantity' => $this->quantity,
            'unit_cost' => ['amount' => $this->unit_cost, 'currency' => $this->goodsReceipt->currency],
        ];
    }
}
