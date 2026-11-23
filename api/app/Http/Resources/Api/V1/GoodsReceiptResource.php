<?php

namespace App\Http\Resources\Api\V1;

use App\Models\GoodsReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GoodsReceipt */
class GoodsReceiptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_order_number' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder->number),
            'status' => $this->status,
            'delivery_note' => $this->delivery_note,
            'notes' => $this->notes,
            'posted_at' => $this->posted_at,
            'lines' => GoodsReceiptLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
