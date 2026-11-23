<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\StockLevelResource;
use App\Http\Resources\Api\V1\StockMovementResource;
use App\Models\StockLevel;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Read-only views of stock. Writes happen only through StockService (check-in and later documents). Guarded by `can:stock.view`. */
class StockController extends Controller
{
    /**
     * List stock levels
     *
     * On-hand per variant, 50 per page, lowest first. Filters: `q` (SKU or product name), `max` (on hand at or below).
     */
    public function levels(Request $request): AnonymousResourceCollection
    {
        $levels = StockLevel::query()->with('variant.product')
            ->when($request->query('q'), function (Builder $query, $term): void {
                $like = '%'.addcslashes((string) $term, '\\%_').'%';
                $query->whereHas('variant', fn (Builder $v) => $v->withTrashed()
                    ->where(fn (Builder $w) => $w->where('sku', 'ilike', $like)
                        ->orWhereHas('product', fn (Builder $p) => $p->where('name', 'ilike', $like))));
            })
            ->when($request->query('max') !== null && $request->query('max') !== '', fn (Builder $q) => $q->where('on_hand', '<=', (int) $request->query('max')))
            ->orderBy('on_hand')->orderBy('variant_id')
            ->paginate(50)->withQueryString();

        return StockLevelResource::collection($levels);
    }

    /**
     * List the stock ledger
     *
     * Newest first, 50 per page. Filters: `variant_id`, `type`, `from` and `to` (dates). Rows are never changed or deleted.
     */
    public function movements(Request $request): AnonymousResourceCollection
    {
        $movements = StockMovement::query()->with('variant')
            ->when($request->query('variant_id'), fn (Builder $q, $id) => $q->where('variant_id', $id))
            ->when($request->query('type'), fn (Builder $q, $type) => $q->where('type', $type))
            ->when($request->query('from'), fn (Builder $q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($request->query('to'), fn (Builder $q, $to) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return StockMovementResource::collection($movements);
    }
}
