<?php

namespace App\Services;

use App\Models\Courier;
use App\Models\Delivery;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Assigns an order to a courier. The cash to collect is fixed here from the order, never sent by a client. */
class DeliveryService
{
    public function assign(Order $order, int $courierId, ?string $trackingNumber): Delivery
    {
        return DB::transaction(function () use ($order, $courierId, $trackingNumber): Delivery {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, [Order::PACKED, Order::SHIPPED], true)) {
                throw ValidationException::withMessages(['status' => 'Only a packed or shipped order can be assigned to a courier.']);
            }
            if (! Courier::query()->whereKey($courierId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['courier_id' => 'This courier is not available.']);
            }

            $delivery = Delivery::query()->where('order_id', $order->id)->first() ?? new Delivery;
            if ($delivery->remittance_id !== null) {
                throw ValidationException::withMessages(['courier_id' => 'This delivery is already in a remittance.']);
            }

            $delivery->forceFill([
                'order_id' => $order->id,
                'courier_id' => $courierId,
                'tracking_number' => $trackingNumber,
                'cod_amount' => $order->payment_method === 'cod' ? $order->grand_total : 0,
            ])->save();

            return $delivery;
        });
    }
}
