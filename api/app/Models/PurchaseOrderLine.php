<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ordered variant. `sku` and `name` are snapshots; `quantity_received` is written only by E3's check-in. */
#[Fillable(['variant_id', 'sku', 'name', 'quantity_ordered', 'unit_cost', 'tax_rate_bp', 'line_total', 'tax_amount'])]
class PurchaseOrderLine extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'integer',
            'quantity_received' => 'integer',
            'unit_cost' => 'integer',
            'tax_rate_bp' => 'integer',
            'line_total' => 'integer',
            'tax_amount' => 'integer',
        ];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class)->withTrashed();
    }
}
