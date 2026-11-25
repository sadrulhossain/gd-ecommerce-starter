<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CancelOrderRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Admin order management. Routes carry `can:orders.view|update|cancel`. */
class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /**
     * List orders
     *
     * Newest first, 20 per page. Filters: `status`, `payment_status`, `q` (number, name or phone).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->when($request->query('status'), fn (Builder $q, $v) => $q->where('status', $v))
            ->when($request->query('payment_status'), fn (Builder $q, $v) => $q->where('payment_status', $v))
            ->when($request->query('q'), function (Builder $query, $term): void {
                $like = '%'.addcslashes((string) $term, '\\%_').'%';
                $query->where(fn (Builder $q) => $q->where('number', 'ilike', $like)
                    ->orWhere('contact_name', 'ilike', $like)->orWhere('contact_phone', 'ilike', $like));
            })
            ->orderByDesc('id')->paginate(20)->withQueryString();

        return OrderResource::collection($orders);
    }

    /**
     * Show an order
     */
    public function show(Order $order): OrderResource
    {
        return new OrderResource($order->load('items'));
    }

    /**
     * Move an order forward
     *
     * pending to confirmed (stock leaves, 422 if short), then packed, shipped, delivered.
     */
    public function advance(Order $order): OrderResource
    {
        return new OrderResource($this->orders->advance($order));
    }

    /**
     * Cancel an order
     *
     * From pending, confirmed or packed. A confirmed or packed order returns its stock.
     */
    public function cancel(CancelOrderRequest $request, Order $order): OrderResource
    {
        return new OrderResource($this->orders->cancel($order, $request->validated('reason')));
    }
}
