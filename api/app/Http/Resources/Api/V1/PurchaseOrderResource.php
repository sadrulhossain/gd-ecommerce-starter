<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin PurchaseOrder */
class PurchaseOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'supplier_id' => $this->supplier_id,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'status' => $this->status,
            'expected_at' => $this->expected_at === null ? null : Carbon::parse($this->expected_at)->toDateString(),
            'notes' => $this->notes,
            'subtotal' => ['amount' => $this->subtotal, 'currency' => $this->currency],
            'tax_total' => ['amount' => $this->tax_total, 'currency' => $this->currency],
            'grand_total' => ['amount' => $this->grand_total, 'currency' => $this->currency],
            'ordered_at' => $this->ordered_at,
            'cancelled_at' => $this->cancelled_at,
            'lines' => PurchaseOrderLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
