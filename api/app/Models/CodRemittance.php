<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A courier's COD cash hand-over: draft, then posted (immutable). `difference` = received minus expected.
 * Written only by CodRemittanceService.
 */
class CodRemittance extends Model
{
    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::DRAFT];

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expected_amount' => 'integer', 'received_amount' => 'integer', 'difference' => 'integer', 'posted_at' => 'datetime'];
    }

    /** Next number from the database sequence: RM-000001. */
    public static function nextNumber(): string
    {
        return 'RM-'.str_pad((string) DB::selectOne("SELECT nextval('cod_remittance_number_seq') AS n")->n, 6, '0', STR_PAD_LEFT);
    }

    /** @return BelongsTo<Courier, $this> */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    /** @return HasMany<Delivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'remittance_id');
    }
}
