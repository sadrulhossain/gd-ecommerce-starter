<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin view of a variant. Money is `{amount, currency}` with `amount` in integer minor units.
 *
 * @mixin ProductVariant
 */
class ProductVariantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'options' => (object) $this->options,
            'price' => $this->money($this->price),
            'compare_at_price' => $this->money($this->compare_at_price),
            'cost_price' => $this->money($this->cost_price),
            'weight_grams' => $this->weight_grams,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }

    /** @return array{amount: int, currency: string}|null */
    private function money(?int $amount): ?array
    {
        return $amount === null ? null : ['amount' => $amount, 'currency' => $this->currency];
    }
}
