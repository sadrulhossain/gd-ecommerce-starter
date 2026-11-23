<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A damage write-off, supplier return, adjustment or stock count: draft (editable), then posted (immutable; it writes
 * the stock ledger through StockService). A mistake is corrected by a new document.
 */
#[Fillable(['reason', 'supplier_id', 'notes'])]
class StockDocument extends Model
{
    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const DAMAGE = 'damage';

    public const RETURN = 'return';

    public const ADJUSTMENT = 'adjustment';

    public const COUNT = 'count';

    /**
     * Per type: URL segment, permission prefix, number prefix and the ledger movement type it writes.
     *
     * @var array<string, array{path: string, permission: string, prefix: string, movement: string}>
     */
    public const TYPES = [
        self::DAMAGE => ['path' => 'damages', 'permission' => 'damages', 'prefix' => 'DM', 'movement' => StockMovement::DAMAGE],
        self::RETURN => ['path' => 'supplier-returns', 'permission' => 'supplier-returns', 'prefix' => 'SR', 'movement' => StockMovement::RETURN_TO_SUPPLIER],
        self::ADJUSTMENT => ['path' => 'adjustments', 'permission' => 'adjustments', 'prefix' => 'ADJ', 'movement' => StockMovement::ADJUSTMENT],
        self::COUNT => ['path' => 'stock-counts', 'permission' => 'stock-counts', 'prefix' => 'SC', 'movement' => StockMovement::COUNT],
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::DRAFT];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }

    /** Next number from the database sequence, with the type's prefix: DM-000001. */
    public static function nextNumber(string $type): string
    {
        return self::TYPES[$type]['prefix'].'-'.str_pad((string) DB::selectOne("SELECT nextval('stock_document_number_seq') AS n")->n, 6, '0', STR_PAD_LEFT);
    }

    /** @throws ValidationException */
    public function assertDraft(): void
    {
        if ($this->status !== self::DRAFT) {
            throw ValidationException::withMessages(['status' => 'A posted document cannot be changed.']);
        }
    }

    /** Load supplier and lines for a response (lines point back at this document, no extra queries). */
    public function withDetails(): static
    {
        $this->load(['supplier', 'lines']);
        $this->lines->each(fn (StockDocumentLine $line) => $line->setRelation('document', $this));

        return $this;
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /** @return HasMany<StockDocumentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockDocumentLine::class)->orderBy('id');
    }
}
