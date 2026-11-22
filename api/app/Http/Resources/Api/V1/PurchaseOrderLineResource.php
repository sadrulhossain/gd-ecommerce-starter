<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PurchaseOrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Money is `{amount, currency}` with `amount` in integer minor units; `tax_rate_bp` is basis points (100 = 1%).
 *
 * @mixin PurchaseOrderLine
 */
class PurchaseOrderLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = $this->purchaseOrder->currency;

        return [
            'id' => $this->id,
            'variant_id' => $this->variant_id,
            'sku' => $this->sku,
            'name' => $this->name,
            'quantity_ordered' => $this->quantity_ordered,
            'quantity_received' => $this->quantity_received,
            'unit_cost' => ['amount' => $this->unit_cost, 'currency' => $currency],
            'tax_rate_bp' => $this->tax_rate_bp,
            'line_total' => ['amount' => $this->line_total, 'currency' => $currency],
            'tax_amount' => ['amount' => $this->tax_amount, 'currency' => $currency],
        ];
    }
}
