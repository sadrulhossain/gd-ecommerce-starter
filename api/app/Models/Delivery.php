<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hand-over of one order to a courier. `cod_amount` is the cash to collect (the grand total for COD, else 0).
 * Written only by DeliveryService and OrderService: `collected_*` is set when the order is delivered.
 */
class Delivery extends Model
{
    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['cod_amount' => 'integer', 'collected_amount' => 'integer', 'collected_at' => 'datetime'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Courier, $this> */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    /** @return BelongsTo<CodRemittance, $this> */
    public function remittance(): BelongsTo
    {
        return $this->belongsTo(CodRemittance::class, 'remittance_id');
    }
}
