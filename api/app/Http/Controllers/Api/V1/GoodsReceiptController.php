<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreGoodsReceiptRequest;
use App\Http\Requests\Api\V1\UpdateGoodsReceiptRequest;
use App\Http\Resources\Api\V1\GoodsReceiptResource;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Services\GoodsReceiptService;
use App\Services\StockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class GoodsReceiptController extends Controller
{
    /**
     * List goods receipts
     *
     * Newest first, 20 per page, without lines. Filters: `status`, `purchase_order_id`, `q` (receipt or order number).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', GoodsReceipt::class);

        $receipts = GoodsReceipt::query()->with('purchaseOrder')
            ->when($request->query('status'), fn (Builder $q, $status) => $q->where('status', $status))
            ->when($request->query('purchase_order_id'), fn (Builder $q, $id) => $q->where('purchase_order_id', $id))
            ->when($request->query('q'), function (Builder $query, $term): void {
                $like = '%'.addcslashes((string) $term, '\\%_').'%';
                $query->where(fn (Builder $q) => $q->where('number', 'ilike', $like)
                    ->orWhereHas('purchaseOrder', fn (Builder $o) => $o->where('number', 'ilike', $like)));
            })
            ->orderByDesc('id')
            ->paginate(20)->withQueryString();

        return GoodsReceiptResource::collection($receipts);
    }

    /** Show a goods receipt with its lines */
    public function show(GoodsReceipt $goodsReceipt): GoodsReceiptResource
    {
        Gate::authorize('view', $goodsReceipt);

        return new GoodsReceiptResource($goodsReceipt->withDetails());
    }

    /**
     * Create a goods receipt (draft)
     *
     * Checks goods in against an `ordered` or `partially_received` purchase order. Optional `lines`
     * (`purchase_order_line_id`, `quantity`, optional `unit_cost` in minor units, defaulting to the order line's cost);
     * a quantity cannot exceed what is still outstanding. Stock changes only when the receipt is posted.
     */
    public function store(StoreGoodsReceiptRequest $request, GoodsReceiptService $service): GoodsReceiptResource
    {
        $data = $request->validated();
        $lines = $data['lines'] ?? [];
        unset($data['lines'], $data['purchase_order_id']);

        $receipt = DB::transaction(function () use ($request, $service, $data, $lines): GoodsReceipt {
            $order = PurchaseOrder::query()->findOrFail($request->integer('purchase_order_id'));

            $receipt = new GoodsReceipt($data);
            $receipt->number = GoodsReceipt::nextNumber();
            $receipt->purchase_order_id = $order->id;
            $receipt->currency = $order->currency;
            $receipt->created_by = $request->user()->id;
            $receipt->save();

            $service->syncLines($receipt, $lines);

            return $receipt;
        });

        return new GoodsReceiptResource($receipt->refresh()->withDetails());
    }

    /** Update a draft goods receipt: `delivery_note`, `notes`, and `lines` (replaces all lines when sent) */
    public function update(UpdateGoodsReceiptRequest $request, GoodsReceipt $goodsReceipt, GoodsReceiptService $service): GoodsReceiptResource
    {
        $goodsReceipt->assertDraft();

        $data = $request->validated();
        $lines = $data['lines'] ?? null;
        unset($data['lines']);

        DB::transaction(function () use ($goodsReceipt, $service, $data, $lines): void {
            $goodsReceipt->update($data);

            if ($lines !== null) {
                $service->syncLines($goodsReceipt, $lines);
            }
        });

        return new GoodsReceiptResource($goodsReceipt->refresh()->withDetails());
    }

    /** Delete a draft goods receipt (posted ones are kept; correct them with a new document) */
    public function destroy(GoodsReceipt $goodsReceipt): Response
    {
        Gate::authorize('delete', $goodsReceipt);

        $goodsReceipt->assertDraft();
        $goodsReceipt->delete();

        return response()->noContent();
    }

    /**
     * Post a goods receipt
     *
     * Adds the stock (one ledger row per line), updates the purchase order's received quantities and status, and makes
     * the receipt immutable. Needs at least one line; quantities are re-checked against the order under a lock.
     */
    public function post(Request $request, GoodsReceipt $goodsReceipt, GoodsReceiptService $service, StockService $stock): GoodsReceiptResource
    {
        Gate::authorize('post', $goodsReceipt);

        $goodsReceipt->assertDraft();
        $posted = $service->post($goodsReceipt, $request->user()->id, $stock);

        return new GoodsReceiptResource($posted->withDetails());
    }
}
