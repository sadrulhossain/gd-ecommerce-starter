<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/** A customer's own orders, plus guest lookup by order number and lookup token. */
class CustomerOrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /**
     * My orders
     *
     * Newest first, 20 per page.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        return OrderResource::collection($customer->orders()->with('items')->orderByDesc('id')->paginate(20));
    }

    /**
     * My order
     */
    public function show(Request $request, string $number): OrderResource
    {
        return new OrderResource($this->mine($request, $number));
    }

    /**
     * Cancel my order
     *
     * Only while the order is still pending.
     */
    public function cancel(Request $request, string $number): OrderResource
    {
        $order = $this->mine($request, $number);
        if ($order->status !== Order::PENDING) {
            throw ValidationException::withMessages(['status' => ['Only a pending order can be cancelled by the customer.']]);
        }

        return new OrderResource($this->orders->cancel($order, 'Cancelled by customer'));
    }

    /**
     * Guest order lookup
     *
     * `?token=` is the `lookup_token` returned at checkout.
     */
    public function lookup(Request $request, string $number): OrderResource
    {
        $order = Order::query()->with('items')->where('number', $number)->firstOrFail();
        abort_unless(hash_equals($order->lookup_token, (string) $request->query('token')), 404);

        return new OrderResource($order);
    }

    private function mine(Request $request, string $number): Order
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        return $customer->orders()->with('items')->where('number', $number)->firstOrFail();
    }
}
