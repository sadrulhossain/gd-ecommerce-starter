<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A purchase order: draft (editable), then ordered (posted, immutable), then received by E3's check-in.
 * `partially_received` and `received` are written only by the check-in; E2 writes draft, ordered and cancelled.
 * Totals are integer minor units computed by PurchaseOrderService, never accepted from a client.
 */
#[Fillable(['supplier_id', 'expected_at', 'notes'])]
class PurchaseOrder extends Model
{
    public const DRAFT = 'draft';

    public const ORDERED = 'ordered';

    public const PARTIALLY_RECEIVED = 'partially_received';

    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::DRAFT, 'currency' => 'BDT'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expected_at' => 'date',
            'ordered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'subtotal' => 'integer',
            'tax_total' => 'integer',
            'grand_total' => 'integer',
        ];
    }

    /** Next number from the database sequence: PO-000001. */
    public static function nextNumber(): string
    {
        return 'PO-'.str_pad((string) DB::selectOne("SELECT nextval('purchase_order_number_seq') AS n")->n, 6, '0', STR_PAD_LEFT);
    }

    /** @throws ValidationException */
    public function assertDraft(): void
    {
        if ($this->status !== self::DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only a draft purchase order can be changed.']);
        }
    }

    /** Load the supplier and lines for a response (lines point back at this order, no extra queries). */
    public function withDetails(): static
    {
        $this->load(['supplier', 'lines']);
        $this->lines->each(fn (PurchaseOrderLine $line) => $line->setRelation('purchaseOrder', $this));

        return $this;
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /** @return HasMany<PurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('id');
    }
}
