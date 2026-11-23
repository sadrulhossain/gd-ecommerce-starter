<?php

namespace App\Http\Resources\Api\V1;

use App\Models\StockDocumentLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `quantity` is units removed (damage, return), the signed change (adjustment) or the counted quantity (count).
 * For a posted count, `expected_quantity` is the on-hand found and `variance` is counted minus expected.
 * Money is `{amount, currency}` in integer minor units.
 *
 * @mixin StockDocumentLine
 */
class StockDocumentLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'variant_id' => $this->variant_id,
            'sku' => $this->sku,
            'name' => $this->name,
            'quantity' => $this->quantity,
            'expected_quantity' => $this->expected_quantity,
            'variance' => $this->expected_quantity === null ? null : $this->quantity - $this->expected_quantity,
            'unit_cost' => $this->unit_cost === null ? null : ['amount' => $this->unit_cost, 'currency' => 'BDT'],
        ];
    }
}
