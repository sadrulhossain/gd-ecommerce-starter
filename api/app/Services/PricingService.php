<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\DeliveryZone;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\TaxRate;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The only place a price is computed. Prices always come from the database, never from a client.
 * Prices are tax-exclusive: a coupon discount comes off first, then tax is added per line. Money is integer minor units.
 */
class PricingService
{
    public function __construct(private readonly CouponService $coupons) {}

    /**
     * @param  array<int, int>  $items  variant id => quantity
     * @return array{lines: list<array<string, mixed>>, subtotal: int, shipping_total: int, tax_total: int, discount_total: int, grand_total: int, currency: string, valid: bool, coupon: Coupon|null, coupon_error: string|null}
     *
     * @throws ValidationException when $strict and any line cannot be sold
     */
    public function quote(array $items, bool $strict = false, ?int $zoneId = null, ?string $couponCode = null): array
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
                'tax_rate_id' => $variant->product->tax_rate_id,
                'discount_amount' => 0,
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

        [$coupon, $discount, $couponError] = $this->applyCoupon($couponCode, $subtotal, $strict && $items !== []);
        $lines = $this->priceLines($lines, $discount);
        $tax = array_sum(array_column($lines, 'tax_amount'));
        $shipping = $items === [] ? 0 : $this->shippingFee($zoneId);

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'shipping_total' => $shipping,
            'tax_total' => $tax,
            'discount_total' => $discount,
            'grand_total' => $subtotal - $discount + $tax + $shipping,
            'currency' => (string) config('payments.currency'),
            'valid' => $valid && $items !== [],
            'coupon' => $coupon,
            'coupon_error' => $couponError,
        ];
    }

    /** @return array{0: Coupon|null, 1: int, 2: string|null} coupon, discount, error (non-strict only) */
    private function applyCoupon(?string $code, int $subtotal, bool $strict): array
    {
        if ($code === null || trim($code) === '') {
            return [null, 0, null];
        }

        try {
            $result = $this->coupons->evaluate($code, $subtotal);

            return [$result['coupon'], $result['discount'], null];
        } catch (ValidationException $e) {
            if ($strict) {
                throw $e;
            }

            return [null, 0, $e->errors()['coupon_code'][0]];
        }
    }

    /**
     * Spreads the discount over the sellable lines in proportion to their totals (the rounding remainder goes
     * to the last one), then adds tax per line on the discounted amount, rounded half-up.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function priceLines(array $lines, int $discount): array
    {
        $priced = array_keys(array_filter($lines, fn (array $l) => isset($l['line_total'])));
        $base = array_sum(array_map(fn (int $i) => $lines[$i]['line_total'], $priced));
        $left = $discount;
        $rates = $this->taxRates();

        foreach ($priced as $n => $i) {
            $share = $n === array_key_last($priced) ? $left : ($base > 0 ? intdiv($discount * $lines[$i]['line_total'], $base) : 0);
            $left -= $share;
            $bp = $this->rateFor($lines[$i]['tax_rate_id'], $rates);

            $lines[$i]['discount_amount'] = $share;
            $lines[$i]['tax_rate_bp'] = $bp;
            $lines[$i]['tax_amount'] = intdiv(($lines[$i]['line_total'] - $share) * $bp + 5000, 10000);
        }

        return $lines;
    }

    /** @return array{rates: Collection<int, TaxRate>, default: int} */
    private function taxRates(): array
    {
        $rates = TaxRate::query()->where('is_active', true)->get();

        $default = 0;
        foreach ($rates as $rate) {
            if ($rate->is_default) {
                $default = $rate->rate_bp;
            }
        }

        return ['rates' => $rates->keyBy('id'), 'default' => $default];
    }

    /** @param  array{rates: Collection<int, TaxRate>, default: int}  $rates */
    private function rateFor(?int $taxRateId, array $rates): int
    {
        $rate = $taxRateId === null ? null : $rates['rates']->get($taxRateId);

        return $rate === null ? $rates['default'] : $rate->rate_bp;
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
