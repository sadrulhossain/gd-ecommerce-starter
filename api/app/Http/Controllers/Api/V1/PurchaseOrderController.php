<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePurchaseOrderRequest;
use App\Http\Requests\Api\V1\UpdatePurchaseOrderRequest;
use App\Http\Resources\Api\V1\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    /**
     * List purchase orders
     *
     * Newest first, 20 per page, without lines. Filters: `status`, `supplier_id`, `q` (number or supplier name),
     * `from` and `to` (dates, on the creation day).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PurchaseOrder::class);

        $orders = PurchaseOrder::query()->with('supplier')
            ->when($request->query('status'), fn (Builder $q, $status) => $q->where('status', $status))
            ->when($request->query('supplier_id'), fn (Builder $q, $id) => $q->where('supplier_id', $id))
            ->when($request->query('q'), function (Builder $query, $term): void {
                $like = '%'.addcslashes((string) $term, '\\%_').'%';
                $query->where(fn (Builder $q) => $q->where('number', 'ilike', $like)
                    ->orWhereHas('supplier', fn (Builder $s) => $s->withTrashed()->where('name', 'ilike', $like)));
            })
            ->when($request->query('from'), fn (Builder $q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($request->query('to'), fn (Builder $q, $to) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('id')
            ->paginate(20)->withQueryString();

        return PurchaseOrderResource::collection($orders);
    }

    /** Show a purchase order with its lines */
    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        Gate::authorize('view', $purchaseOrder);

        return new PurchaseOrderResource($purchaseOrder->withDetails());
    }

    /**
     * Create a purchase order
     *
     * A draft with the next `PO-` number, optionally with `lines` (`variant_id`, `quantity_ordered`, optional `unit_cost`
     * in minor units, defaulting to the variant's cost price, and `tax_rate_bp` in basis points). Totals are computed
     * server-side.
     */
    public function store(StorePurchaseOrderRequest $request, PurchaseOrderService $service): PurchaseOrderResource
    {
        $data = $request->validated();
        $lines = $data['lines'] ?? [];
        unset($data['lines']);

        $order = DB::transaction(function () use ($request, $service, $data, $lines): PurchaseOrder {
            $order = new PurchaseOrder($data);
            $order->number = PurchaseOrder::nextNumber();
            $order->created_by = $request->user()->id;
            $order->save();

            foreach (array_values($lines) as $i => $line) {
                $service->addLine($order, $line, "lines.{$i}.unit_cost");
            }

            return $service->recalculate($order);
        });

        return new PurchaseOrderResource($order->refresh()->withDetails());
    }

    /** Update the header of a draft purchase order (`supplier_id`, `expected_at`, `notes`) */
    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $purchaseOrder->assertDraft();
        $purchaseOrder->update($request->validated());

        return new PurchaseOrderResource($purchaseOrder->withDetails());
    }

    /** Delete a draft purchase order (ordered and cancelled ones are kept for history) */
    public function destroy(PurchaseOrder $purchaseOrder): Response
    {
        Gate::authorize('delete', $purchaseOrder);

        $purchaseOrder->assertDraft();
        $purchaseOrder->delete();

        return response()->noContent();
    }

    /**
     * Order a draft purchase order
     *
     * Posts it: it needs at least one line and an active supplier, and is immutable afterwards.
     * Correct a mistake by cancelling it and creating a new one.
     */
    public function order(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        Gate::authorize('order', $purchaseOrder);

        $purchaseOrder->assertDraft();

        if (! $purchaseOrder->lines()->exists()) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line before ordering.']);
        }

        if (! $purchaseOrder->supplier()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['supplier_id' => 'The supplier is inactive.']);
        }

        $purchaseOrder->forceFill(['status' => PurchaseOrder::ORDERED, 'ordered_at' => now()])->save();

        return new PurchaseOrderResource($purchaseOrder->withDetails());
    }

    /**
     * Cancel an ordered purchase order
     *
     * Only while nothing has been received (422 otherwise).
     */
    public function cancel(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        Gate::authorize('cancel', $purchaseOrder);

        if ($purchaseOrder->status !== PurchaseOrder::ORDERED || $purchaseOrder->lines()->where('quantity_received', '>', 0)->exists()) {
            throw ValidationException::withMessages(['status' => 'Only an ordered purchase order with nothing received can be cancelled. Delete a draft instead.']);
        }

        $purchaseOrder->forceFill(['status' => PurchaseOrder::CANCELLED, 'cancelled_at' => now()])->save();

        return new PurchaseOrderResource($purchaseOrder->withDetails());
    }
}
