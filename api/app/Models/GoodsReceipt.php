<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A check-in against a purchase order: draft (editable), then posted (immutable; it writes the stock ledger). */
#[Fillable(['delivery_note', 'notes'])]
class GoodsReceipt extends Model
{
    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::DRAFT, 'currency' => 'BDT'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }

    /** Next number from the database sequence: GR-000001. */
    public static function nextNumber(): string
    {
        return 'GR-'.str_pad((string) DB::selectOne("SELECT nextval('goods_receipt_number_seq') AS n")->n, 6, '0', STR_PAD_LEFT);
    }

    /** @throws ValidationException */
    public function assertDraft(): void
    {
        if ($this->status !== self::DRAFT) {
            throw ValidationException::withMessages(['status' => 'A posted goods receipt cannot be changed.']);
        }
    }

    /** Load the order and lines for a response (lines point back at this receipt, no extra queries). */
    public function withDetails(): static
    {
        $this->load(['purchaseOrder', 'lines']);
        $this->lines->each(fn (GoodsReceiptLine $line) => $line->setRelation('goodsReceipt', $this));

        return $this;
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return HasMany<GoodsReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class)->orderBy('id');
    }
}
