<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
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
     * What can still be sold: on hand minus what pending (placed, not yet confirmed) orders already claim.
     * Stock leaves the ledger only at confirm, so a pending order is a reservation computed from its items.
     *
     * @param  list<int>  $variantIds
     * @return array<int, int> variant id => units available (never negative)
     */
    public function available(array $variantIds): array
    {
        $onHand = StockLevel::query()->whereIn('variant_id', $variantIds)->pluck('on_hand', 'variant_id');
        $reserved = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', Order::PENDING)
            ->whereIn('order_items.variant_id', $variantIds)
            ->groupBy('order_items.variant_id')
            ->selectRaw('order_items.variant_id, SUM(order_items.quantity) AS quantity')
            ->pluck('quantity', 'variant_id');

        $available = [];
        foreach ($variantIds as $id) {
            $available[$id] = max(0, (int) ($onHand[$id] ?? 0) - (int) ($reserved[$id] ?? 0));
        }

        return $available;
    }

    /**
     * Lock these variants' level rows until the surrounding transaction ends, so two checkouts cannot both claim the
     * same units. Rows are locked in id order to avoid deadlocks. Call it inside a transaction.
     *
     * @param  list<int>  $variantIds
     */
    public function lock(array $variantIds): void
    {
        StockLevel::query()->whereIn('variant_id', $variantIds)->orderBy('variant_id')->lockForUpdate()->get();
    }

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
