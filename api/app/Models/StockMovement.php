<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ledger row. Append-only (the database rejects UPDATE and DELETE); created only by StockService. */
class StockMovement extends Model
{
    public const RECEIPT = 'receipt';

    public const DAMAGE = 'damage';

    public const RETURN_TO_SUPPLIER = 'return_to_supplier';

    public const ADJUSTMENT = 'adjustment';

    public const COUNT = 'count';

    public const SALE = 'sale';

    public const SALE_CANCEL = 'sale_cancel';

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
