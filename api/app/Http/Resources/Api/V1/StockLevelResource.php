<?php

namespace App\Http\Resources\Api\V1;

use App\Models\StockLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockLevel */
class StockLevelResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'variant_id' => $this->variant_id,
            'sku' => $this->variant->sku,
            'name' => $this->variant->name,
            'product' => $this->variant->product?->name,
            'on_hand' => $this->on_hand,
            'updated_at' => $this->updated_at,
        ];
    }
}
