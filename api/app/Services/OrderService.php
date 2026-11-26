<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockMovement;
use App\Payments\PaymentManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Places orders and moves them through the lifecycle. A pending order reserves its units (StockService::available());
 * stock leaves the ledger at confirm and returns on a later cancel.
 */
class OrderService
{
    /** status => statuses it may move to (cancel is separate). */
    private const NEXT = [
        Order::PENDING => Order::CONFIRMED,
        Order::CONFIRMED => Order::PACKED,
        Order::PACKED => Order::SHIPPED,
        Order::SHIPPED => Order::DELIVERED,
    ];

    public function __construct(
        private readonly PricingService $pricing,
        private readonly StockService $stock,
        private readonly PaymentManager $payments,
        private readonly CouponService $coupons,
    ) {}

    /**
     * @param  array<int, int>  $items  variant id => quantity (from the cart)
     * @param  array<string, mixed>  $data  validated checkout fields
     */
    public function place(array $items, array $data, ?Customer $customer): Order
    {
        $zoneId = isset($data['delivery_zone_id']) ? (int) $data['delivery_zone_id'] : null;
        $couponCode = isset($data['coupon_code']) ? (string) $data['coupon_code'] : null;

        return DB::transaction(function () use ($items, $data, $customer, $zoneId, $couponCode): Order {
            // Lock first, then quote: the stock check and the order that claims the stock are one step.
            $this->stock->lock(array_keys($items));
            $quote = $this->pricing->quote($items, strict: true, zoneId: $zoneId, couponCode: $couponCode);

            $order = new Order;
            $order->forceFill([
                'number' => Order::nextNumber(),
                'delivery_zone_id' => $zoneId,
                'customer_id' => $customer?->id,
                'lookup_token' => Str::random(40),
                'payment_method' => $data['payment_method'],
                'currency' => $quote['currency'],
                'subtotal' => $quote['subtotal'],
                'shipping_total' => $quote['shipping_total'],
                'tax_total' => $quote['tax_total'],
                'discount_total' => $quote['discount_total'],
                'coupon_code' => $quote['coupon']?->code,
                'grand_total' => $quote['grand_total'],
                'contact_name' => $data['contact_name'],
                'contact_phone' => $data['contact_phone'],
                'contact_email' => $data['contact_email'] ?? null,
                'address_line' => $data['address_line'],
                'city' => $data['city'],
                'district' => $data['district'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
                'note' => $data['note'] ?? null,
            ])->save();

            foreach ($quote['lines'] as $line) {
                (new OrderItem)->forceFill([
                    'order_id' => $order->id,
                    'variant_id' => $line['variant_id'],
                    'sku' => $line['sku'],
                    'name' => $line['name'],
                    'options' => $line['options'] ?: null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'discount_amount' => $line['discount_amount'],
                    'tax_rate_bp' => $line['tax_rate_bp'],
                    'tax_amount' => $line['tax_amount'],
                    'line_total' => $line['line_total'],
                ])->save();
            }

            if ($quote['coupon'] !== null) {
                $this->coupons->redeem($quote['coupon'], $order, $quote['discount_total']);
            }

            $event = $this->payments->initiate($order->payment_method, "order-{$order->id}-initiate", 'orders', $order->id, $order->grand_total);
            $order->forceFill(['payment_status' => $event->status])->save();

            return $order->load('items');
        });
    }

    public function advance(Order $order, ?int $userId = null): Order
    {
        return DB::transaction(function () use ($order, $userId): Order {
            $order = $this->lock($order);
            $next = self::NEXT[$order->status] ?? null;
            if ($next === null) {
                throw ValidationException::withMessages(['status' => "A {$order->status} order cannot move forward."]);
            }

            if ($next === Order::CONFIRMED) {
                foreach ($order->items->sortBy('variant_id') as $item) {
                    $this->stock->apply($item->variant_id, StockMovement::SALE, -$item->quantity, $order, null, $userId, $order->number);
                }
            }

            $delivery = Delivery::query()->where('order_id', $order->id)->first();
            if ($next === Order::SHIPPED && $delivery === null) {
                throw ValidationException::withMessages(['delivery' => 'Assign a courier before shipping.']);
            }

            $changes = ['status' => $next, "{$next}_at" => now()];
            if ($next === Order::DELIVERED && $delivery !== null) {
                // The courier now holds the customer's cash (COD) until it is remitted.
                $delivery->forceFill(['collected_amount' => $delivery->cod_amount, 'collected_at' => now()])->save();
                if ($delivery->cod_amount > 0) {
                    $changes['payment_status'] = 'paid';
                }
            }
            $order->forceFill($changes)->save();

            return $order;
        });
    }

    public function cancel(Order $order, ?string $reason, ?int $userId = null): Order
    {
        return DB::transaction(function () use ($order, $reason, $userId): Order {
            $order = $this->lock($order);
            if (! in_array($order->status, [Order::PENDING, Order::CONFIRMED, Order::PACKED], true)) {
                throw ValidationException::withMessages(['status' => "A {$order->status} order cannot be cancelled."]);
            }

            if ($order->status !== Order::PENDING) {
                foreach ($order->items->sortBy('variant_id') as $item) {
                    $this->stock->apply($item->variant_id, StockMovement::SALE_CANCEL, $item->quantity, $order, null, $userId, $order->number);
                }
            }

            $this->coupons->release($order);
            $order->forceFill(['status' => Order::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();

            return $order;
        });
    }

    private function lock(Order $order): Order
    {
        return Order::query()->with('items')->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }
}
