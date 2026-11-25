<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Validates coupon codes, computes the discount, and counts redemptions (one per order). */
class CouponService
{
    /**
     * @return array{coupon: Coupon, discount: int}
     *
     * @throws ValidationException (`coupon_code`) when the code cannot be used on this subtotal
     */
    public function evaluate(string $code, int $subtotal): array
    {
        $coupon = Coupon::query()->where('code', strtoupper(trim($code)))->first();
        $now = now();

        $problem = match (true) {
            $coupon === null || ! $coupon->is_active => 'This coupon code is not valid.',
            $coupon->starts_at !== null && $coupon->starts_at->isFuture() => 'This coupon is not active yet.',
            $coupon->ends_at !== null && $coupon->ends_at->lt($now) => 'This coupon has expired.',
            $coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit => 'This coupon has been fully used.',
            $subtotal < $coupon->min_subtotal => 'The order is below the minimum for this coupon.',
            default => null,
        };
        if ($problem !== null) {
            throw ValidationException::withMessages(['coupon_code' => [$problem]]);
        }

        /** @var Coupon $coupon */
        $discount = $coupon->type === Coupon::PERCENT
            ? intdiv($subtotal * $coupon->value, 10000)
            : $coupon->value;
        if ($coupon->max_discount !== null) {
            $discount = min($discount, $coupon->max_discount);
        }

        return ['coupon' => $coupon, 'discount' => max(0, min($discount, $subtotal))];
    }

    /** Count a redemption for a placed order. Call inside the order transaction. */
    public function redeem(Coupon $coupon, Order $order, int $amount): void
    {
        $locked = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
        if ($locked->usage_limit !== null && $locked->used_count >= $locked->usage_limit) {
            throw ValidationException::withMessages(['coupon_code' => ['This coupon has been fully used.']]);
        }

        $locked->forceFill(['used_count' => $locked->used_count + 1])->save();
        DB::table('coupon_redemptions')->insert([
            'coupon_id' => $locked->id, 'order_id' => $order->id, 'amount' => $amount, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Give the use back when its order is cancelled. */
    public function release(Order $order): void
    {
        $redemption = DB::table('coupon_redemptions')->where('order_id', $order->id)->first();
        if ($redemption === null) {
            return;
        }

        Coupon::query()->whereKey($redemption->coupon_id)->where('used_count', '>', 0)->lockForUpdate()->decrement('used_count');
        DB::table('coupon_redemptions')->where('id', $redemption->id)->delete();
    }
}
