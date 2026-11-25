<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order with its snapshot lines. Money is `{amount, currency}`, amount in integer minor units.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $money = fn (int $amount): array => ['amount' => $amount, 'currency' => $this->currency];

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'customer_id' => $this->customer_id,
            'subtotal' => $money($this->subtotal),
            'shipping_total' => $money($this->shipping_total),
            'tax_total' => $money($this->tax_total),
            'discount_total' => $money($this->discount_total),
            'grand_total' => $money($this->grand_total),
            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'address_line' => $this->address_line,
            'city' => $this->city,
            'district' => $this->district,
            'postal_code' => $this->postal_code,
            'note' => $this->note,
            'cancel_reason' => $this->cancel_reason,
            'placed_at' => $this->created_at,
            'confirmed_at' => $this->confirmed_at,
            'packed_at' => $this->packed_at,
            'shipped_at' => $this->shipped_at,
            'delivered_at' => $this->delivered_at,
            'cancelled_at' => $this->cancelled_at,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'variant_id' => $item->variant_id,
                'sku' => $item->sku,
                'name' => $item->name,
                'options' => (object) ($item->options ?? []),
                'quantity' => $item->quantity,
                'unit_price' => $money($item->unit_price),
                'tax_amount' => $money($item->tax_amount),
                'line_total' => $money($item->line_total),
            ])->all()),
        ];
    }
}
