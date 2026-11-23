<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Draft lines and posting for goods receipts. Posting writes stock only through StockService. */
class GoodsReceiptService
{
    /**
     * Replace the lines of a draft. Each entry needs `purchase_order_line_id` and `quantity`; `unit_cost` defaults to the
     * order line's cost. Quantities may not exceed what is still outstanding on the order.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function syncLines(GoodsReceipt $receipt, array $lines): void
    {
        $orderLines = PurchaseOrderLine::query()->where('purchase_order_id', $receipt->purchase_order_id)->get()->keyBy('id');
        $rows = [];

        foreach ($lines as $i => $line) {
            $orderLine = $orderLines->get((int) $line['purchase_order_line_id']);

            if ($orderLine === null) {
                throw ValidationException::withMessages(["lines.{$i}.purchase_order_line_id" => 'This line does not belong to the purchase order.']);
            }

            $outstanding = $orderLine->quantity_ordered - $orderLine->quantity_received;

            if ((int) $line['quantity'] > $outstanding) {
                throw ValidationException::withMessages(["lines.{$i}.quantity" => "Only {$outstanding} still outstanding on this line."]);
            }

            $rows[] = [
                'purchase_order_line_id' => $orderLine->id,
                'variant_id' => $orderLine->variant_id,
                'sku' => $orderLine->sku,
                'name' => $orderLine->name,
                'quantity' => (int) $line['quantity'],
                'unit_cost' => (int) ($line['unit_cost'] ?? $orderLine->unit_cost),
            ];
        }

        $receipt->lines()->delete();
        $receipt->lines()->createMany($rows);
    }

    /**
     * Post a draft: lock the order, re-check outstanding quantities, add the stock, update the order. Immutable afterwards.
     *
     * @throws ValidationException
     */
    public function post(GoodsReceipt $receipt, int $userId, StockService $stock): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $userId, $stock): GoodsReceipt {
            $receipt = GoodsReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $receipt->assertDraft();

            $order = PurchaseOrder::query()->whereKey($receipt->purchase_order_id)->lockForUpdate()->firstOrFail();

            if (! in_array($order->status, [PurchaseOrder::ORDERED, PurchaseOrder::PARTIALLY_RECEIVED], true)) {
                throw ValidationException::withMessages(['purchase_order_id' => 'The purchase order is not open for receiving.']);
            }

            $lines = $receipt->lines()->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Add at least one line before posting.']);
            }

            $orderLines = $order->lines()->lockForUpdate()->get()->keyBy('id');

            foreach ($lines as $i => $line) {
                $orderLine = $orderLines->get($line->purchase_order_line_id);
                $outstanding = $orderLine->quantity_ordered - $orderLine->quantity_received;

                if ($line->quantity > $outstanding) {
                    throw ValidationException::withMessages(["lines.{$i}.quantity" => "Only {$outstanding} still outstanding on this line."]);
                }

                $orderLine->forceFill(['quantity_received' => $orderLine->quantity_received + $line->quantity])->save();
                $stock->apply($line->variant_id, StockMovement::RECEIPT, $line->quantity, $receipt, $line->unit_cost, $userId);
            }

            $complete = $orderLines->every(fn (PurchaseOrderLine $l) => $l->quantity_received >= $l->quantity_ordered);
            $order->forceFill(['status' => $complete ? PurchaseOrder::RECEIVED : PurchaseOrder::PARTIALLY_RECEIVED])->save();

            $receipt->forceFill(['status' => GoodsReceipt::POSTED, 'posted_at' => now(), 'posted_by' => $userId])->save();

            return $receipt;
        });
    }
}
