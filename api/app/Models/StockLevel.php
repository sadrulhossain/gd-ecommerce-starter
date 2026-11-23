<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** On-hand cache per variant. Written only by StockService; never mass-assigned. */
class StockLevel extends Model
{
    protected $primaryKey = 'variant_id';

    public $incrementing = false;

    const CREATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['variant_id' => 'integer', 'on_hand' => 'integer'];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class)->withTrashed();
    }
}
