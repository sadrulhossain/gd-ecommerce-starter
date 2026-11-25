<?php

namespace App\Services;

use App\Models\DeliveryZone;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use Illuminate\Validation\ValidationException;

/**
 * The only place a price is computed. Prices always come from the database, never from a client.
 * Tax and discounts are 0 until E8. Money is integer minor units.
 */
class PricingService
{
    /**
     * @param  array<int, int>  $items  variant id => quantity
     * @return array{lines: list<array<string, mixed>>, subtotal: int, shipping_total: int, tax_total: int, discount_total: int, grand_total: int, currency: string, valid: bool}
     *
     * @throws ValidationException when $strict and any line cannot be sold
     */
    public function quote(array $items, bool $strict = false, ?int $zoneId = null): array
    {
        $variants = ProductVariant::query()->with('product')->whereIn('id', array_keys($items))->get()->keyBy('id');
        $stock = StockLevel::query()->whereIn('variant_id', array_keys($items))->pluck('on_hand', 'variant_id');

        $lines = [];
        $subtotal = 0;
        $valid = true;

        foreach ($items as $variantId => $quantity) {
            /** @var ProductVariant|null $variant */
            $variant = $variants->get($variantId);
            if ($variant !== null && $variant->product === null) {
                $variant = null; // its product was deleted
            }
            $issue = $this->issue($variant, $quantity, (int) ($stock[$variantId] ?? 0));

            if ($variant === null) {
                $valid = false;
                $lines[] = ['variant_id' => $variantId, 'quantity' => $quantity, 'available' => false, 'issue' => $issue];

                continue;
            }

            $lineTotal = $variant->price * $quantity;
            $valid = $valid && $issue === null;
            $subtotal += $lineTotal;
            $lines[] = [
                'variant_id' => $variantId,
                'sku' => $variant->sku,
                'name' => $variant->product->name.($variant->name ? ' - '.$variant->name : ''),
                'options' => $variant->options,
                'quantity' => $quantity,
                'unit_price' => $variant->price,
                'tax_rate_bp' => 0,
                'tax_amount' => 0,
                'line_total' => $lineTotal,
                'available' => $issue === null,
                'issue' => $issue,
            ];
        }

        if ($strict && ! $valid) {
            $messages = [];
            foreach ($lines as $i => $line) {
                if (! $line['available']) {
                    $messages["items.{$i}"] = [$line['issue']];
                }
            }
            throw ValidationException::withMessages($messages ?: ['items' => ['The cart is empty.']]);
        }

        $shipping = $items === [] ? 0 : $this->shippingFee($zoneId);

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'shipping_total' => $shipping,
            'tax_total' => 0,
            'discount_total' => 0,
            'grand_total' => $subtotal + $shipping,
            'currency' => (string) config('payments.currency'),
            'valid' => $valid && $items !== [],
        ];
    }

    /** The active zone's fee, else the flat default. */
    private function shippingFee(?int $zoneId): int
    {
        $fee = $zoneId === null ? null : DeliveryZone::query()->whereKey($zoneId)->where('is_active', true)->value('shipping_fee');

        return $fee === null ? (int) config('shop.shipping_flat') : (int) $fee;
    }

    private function issue(?ProductVariant $variant, int $quantity, int $onHand): ?string
    {
        if ($variant === null || ! $variant->is_active || $variant->product->status !== Product::ACTIVE
            || ! $variant->product->category()->where('is_active', true)->exists()) {
            return 'This item is no longer available.';
        }

        return $onHand < $quantity ? "Only {$onHand} in stock." : null;
    }
}
