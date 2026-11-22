<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Illuminate\Validation\ValidationException;

/** Line snapshots and money maths for purchase orders. All amounts are integer minor units; clients never send totals. */
class PurchaseOrderService
{
    public const MAX_QUANTITY = 1_000_000;

    public const MAX_UNIT_COST = 10_000_000_000;

    /**
     * Line total (before tax) and tax; tax is rounded half-up from basis points (100 bp = 1%).
     *
     * @return array{line_total: int, tax_amount: int}
     */
    public function amounts(int $quantity, int $unitCost, int $taxRateBp): array
    {
        $total = $quantity * $unitCost;

        return ['line_total' => $total, 'tax_amount' => intdiv($total * $taxRateBp + 5000, 10000)];
    }

    /**
     * Add a variant line to a draft order. `unit_cost` falls back to the variant's cost price.
     *
     * @param  array<string, mixed>  $data  variant_id, quantity_ordered, optional unit_cost and tax_rate_bp
     * @param  string  $costKey  validation key to report a missing cost under
     */
    public function addLine(PurchaseOrder $order, array $data, string $costKey = 'unit_cost'): PurchaseOrderLine
    {
        $variant = ProductVariant::query()->with('product')->findOrFail($data['variant_id']);
        $unitCost = $data['unit_cost'] ?? $variant->cost_price;

        if ($unitCost === null) {
            throw ValidationException::withMessages([$costKey => 'This variant has no cost price; send a unit cost.']);
        }

        $quantity = (int) $data['quantity_ordered'];
        $taxRate = (int) ($data['tax_rate_bp'] ?? 0);

        return $order->lines()->create([
            'variant_id' => $variant->id,
            'sku' => $variant->sku,
            'name' => $variant->name !== null && $variant->name !== '' ? "{$variant->product->name} - {$variant->name}" : $variant->product->name,
            'quantity_ordered' => $quantity,
            'unit_cost' => (int) $unitCost,
            'tax_rate_bp' => $taxRate,
            ...$this->amounts($quantity, (int) $unitCost, $taxRate),
        ]);
    }

    /** @param  array<string, mixed>  $data  quantity_ordered, unit_cost, tax_rate_bp (any of them) */
    public function updateLine(PurchaseOrderLine $line, array $data): PurchaseOrderLine
    {
        $line->fill($data);
        $line->fill($this->amounts($line->quantity_ordered, $line->unit_cost, $line->tax_rate_bp))->save();

        return $line;
    }

    /** Recompute the order totals from its lines. */
    public function recalculate(PurchaseOrder $order): PurchaseOrder
    {
        $lines = $order->lines()->get();
        $subtotal = (int) $lines->sum('line_total');
        $tax = (int) $lines->sum('tax_amount');

        $order->forceFill(['subtotal' => $subtotal, 'tax_total' => $tax, 'grand_total' => $subtotal + $tax])->save();

        return $order;
    }
}
