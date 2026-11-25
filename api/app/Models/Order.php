<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * An order. Created only by OrderService::place(); totals are integer minor units computed by PricingService.
 * Lifecycle: pending, confirmed (stock leaves), packed, shipped, delivered; cancelled from pending, confirmed or packed.
 * Nothing here is mass-assignable: services set every field.
 */
class Order extends Model
{
    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const PACKED = 'packed';

    public const SHIPPED = 'shipped';

    public const DELIVERED = 'delivered';

    public const CANCELLED = 'cancelled';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::PENDING, 'currency' => 'BDT'];

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = ['lookup_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'shipping_total' => 'integer',
            'tax_total' => 'integer',
            'discount_total' => 'integer',
            'grand_total' => 'integer',
            'confirmed_at' => 'datetime',
            'packed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** Next number from the database sequence: ORD-000001. */
    public static function nextNumber(): string
    {
        return 'ORD-'.str_pad((string) DB::selectOne("SELECT nextval('order_number_seq') AS n")->n, 6, '0', STR_PAD_LEFT);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
