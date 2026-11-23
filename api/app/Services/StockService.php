<?php

namespace App\Services;

use App\Models\StockLevel;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of stock. Every change appends one ledger row and moves the cached level in the same
 * transaction, under a row lock on the variant's level, so two requests can never both pass the same check.
 */
class StockService
{
    /**
     * Set a variant's stock to a counted quantity: under the row lock, writes one `count` movement for the difference
     * (none when the count matches). Returns the on-hand it found and the signed difference.
     *
     * @return array{expected: int, delta: int}
     */
    public function count(int $variantId, int $counted, Model $reference, ?int $userId = null): array
    {
        return DB::transaction(function () use ($variantId, $counted, $reference, $userId): array {
            StockLevel::query()->insertOrIgnore(['variant_id' => $variantId, 'on_hand' => 0]);
            $expected = StockLevel::query()->whereKey($variantId)->lockForUpdate()->firstOrFail()->on_hand;
            $delta = $counted - $expected;

            if ($delta !== 0) {
                $this->apply($variantId, StockMovement::COUNT, $delta, $reference, null, $userId);
            }

            return ['expected' => $expected, 'delta' => $delta];
        });
    }

    /**
     * Apply a signed quantity to a variant and return the ledger row.
     *
     * @throws ValidationException when the result would be negative
     */
    public function apply(int $variantId, string $type, int $quantity, Model $reference, ?int $unitCost = null, ?int $userId = null, ?string $note = null): StockMovement
    {
        if ($quantity === 0) {
            throw new \InvalidArgumentException('A stock movement needs a non-zero quantity.');
        }

        return DB::transaction(function () use ($variantId, $type, $quantity, $reference, $unitCost, $userId, $note): StockMovement {
            StockLevel::query()->insertOrIgnore(['variant_id' => $variantId, 'on_hand' => 0]);
            $level = StockLevel::query()->whereKey($variantId)->lockForUpdate()->firstOrFail();

            $balance = $level->on_hand + $quantity;

            if ($balance < 0) {
                throw ValidationException::withMessages(['quantity' => "Not enough stock for variant {$variantId}: {$level->on_hand} on hand."]);
            }

            $level->forceFill(['on_hand' => $balance])->save();

            $movement = new StockMovement;
            $movement->forceFill([
                'variant_id' => $variantId,
                'type' => $type,
                'quantity' => $quantity,
                'balance_after' => $balance,
                'unit_cost' => $unitCost,
                'reference_type' => $reference->getTable(),
                'reference_id' => $reference->getKey(),
                'note' => $note,
                'created_by' => $userId,
            ])->save();

            return $movement;
        });
    }
}
