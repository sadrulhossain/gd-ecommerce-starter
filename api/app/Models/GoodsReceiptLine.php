<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Quantity checked in for one purchase order line. `sku`, `name` and `unit_cost` are snapshots. */
#[Fillable(['purchase_order_line_id', 'variant_id', 'sku', 'name', 'quantity', 'unit_cost'])]
class GoodsReceiptLine extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_cost' => 'integer'];
    }

    /** @return BelongsTo<GoodsReceipt, $this> */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }
}
