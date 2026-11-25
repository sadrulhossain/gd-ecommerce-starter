<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only list of deliveries, to build COD remittances from. Route carries `can:cod-remittances.view`. */
class DeliveryController extends Controller
{
    /**
     * List deliveries
     *
     * Newest first, 50 per page. Filters: `courier_id`, `collectable` (true: cash collected and not yet in a remittance).
     */
    public function index(Request $request): JsonResponse
    {
        $page = Delivery::query()->with('order:id,number')
            ->when($request->query('courier_id'), fn (Builder $q, $id) => $q->where('courier_id', $id))
            ->when($request->boolean('collectable'), fn (Builder $q) => $q->whereNotNull('collected_at')->whereNull('remittance_id'))
            ->orderByDesc('id')->paginate(50)->withQueryString();

        $currency = (string) config('payments.currency');
        $money = fn (?int $amount): ?array => $amount === null ? null : ['amount' => $amount, 'currency' => $currency];

        return response()->json([
            'data' => $page->getCollection()->map(fn (Delivery $d) => [
                'id' => $d->id,
                'order_id' => $d->order_id,
                'order_number' => $d->order->number,
                'courier_id' => $d->courier_id,
                'tracking_number' => $d->tracking_number,
                'cod_amount' => $money($d->cod_amount),
                'collected_amount' => $money($d->collected_amount),
                'collected_at' => $d->collected_at,
                'remittance_id' => $d->remittance_id,
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }
}
