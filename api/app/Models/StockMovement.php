<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ledger row. Append-only (the database rejects UPDATE and DELETE); created only by StockService. */
class StockMovement extends Model
{
    public const RECEIPT = 'receipt';

    const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'balance_after' => 'integer', 'unit_cost' => 'integer'];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class)->withTrashed();
    }
}
