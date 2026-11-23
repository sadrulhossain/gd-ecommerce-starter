<?php

namespace App\Http\Resources\Api\V1;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ledger row; `quantity` is signed, `unit_cost` is integer minor units.
 *
 * @mixin StockMovement
 */
class StockMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'variant_id' => $this->variant_id,
            'sku' => $this->whenLoaded('variant', fn () => $this->variant->sku),
            'type' => $this->type,
            'quantity' => $this->quantity,
            'balance_after' => $this->balance_after,
            'unit_cost' => $this->unit_cost === null ? null : ['amount' => $this->unit_cost, 'currency' => 'BDT'],
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'note' => $this->note,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
        ];
    }
}
