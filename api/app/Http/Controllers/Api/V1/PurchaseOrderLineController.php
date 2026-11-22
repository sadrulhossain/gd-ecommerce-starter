<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePurchaseOrderLineRequest;
use App\Http\Requests\Api\V1\UpdatePurchaseOrderLineRequest;
use App\Http\Resources\Api\V1\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Services\PurchaseOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Lines of a draft purchase order. Each call returns the whole order, since the totals change. */
class PurchaseOrderLineController extends Controller
{
    /**
     * Add a line to a draft purchase order
     *
     * `unit_cost` (minor units) defaults to the variant's cost price; one line per variant.
     */
    public function store(StorePurchaseOrderLineRequest $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $service): PurchaseOrderResource
    {
        $purchaseOrder->assertDraft();

        DB::transaction(function () use ($request, $purchaseOrder, $service): void {
            $service->addLine($purchaseOrder, $request->validated());
            $service->recalculate($purchaseOrder);
        });

        return new PurchaseOrderResource($purchaseOrder->withDetails());
    }

    /** Change the quantity, unit cost or tax rate of a line on a draft purchase order */
    public function update(UpdatePurchaseOrderLineRequest $request, PurchaseOrderLine $line, PurchaseOrderService $service): PurchaseOrderResource
    {
        $order = $line->purchaseOrder;
        $order->assertDraft();

        DB::transaction(function () use ($request, $line, $order, $service): void {
            $service->updateLine($line, $request->validated());
            $service->recalculate($order);
        });

        return new PurchaseOrderResource($order->withDetails());
    }

    /** Remove a line from a draft purchase order */
    public function destroy(PurchaseOrderLine $line, PurchaseOrderService $service): PurchaseOrderResource
    {
        $order = $line->purchaseOrder;
        Gate::authorize('update', $order);
        $order->assertDraft();

        DB::transaction(function () use ($line, $order, $service): void {
            $line->delete();
            $service->recalculate($order);
        });

        return new PurchaseOrderResource($order->withDetails());
    }
}
