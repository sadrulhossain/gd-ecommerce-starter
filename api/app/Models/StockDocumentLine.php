<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One variant on a stock document. `sku` and `name` are snapshots; `expected_quantity` is set when a count is posted. */
#[Fillable(['variant_id', 'sku', 'name', 'quantity', 'unit_cost'])]
class StockDocumentLine extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'expected_quantity' => 'integer', 'unit_cost' => 'integer'];
    }

    /** @return BelongsTo<StockDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class, 'stock_document_id');
    }
}
