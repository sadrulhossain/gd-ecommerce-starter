<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PostCodRemittanceRequest;
use App\Http\Requests\Api\V1\StoreCodRemittanceRequest;
use App\Http\Resources\Api\V1\CodRemittanceResource;
use App\Models\CodRemittance;
use App\Services\CodRemittanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** COD remittances: a courier hands over the cash it collected. Draft, then posted (immutable). */
class CodRemittanceController extends Controller
{
    public function __construct(private readonly CodRemittanceService $remittances) {}

    /**
     * List COD remittances
     *
     * Newest first, 20 per page. Filters: `status`, `courier_id`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $items = CodRemittance::query()
            ->when($request->query('status'), fn (Builder $q, $v) => $q->where('status', $v))
            ->when($request->query('courier_id'), fn (Builder $q, $v) => $q->where('courier_id', $v))
            ->orderByDesc('id')->paginate(20)->withQueryString();

        return CodRemittanceResource::collection($items);
    }

    /**
     * Show a COD remittance
     */
    public function show(CodRemittance $codRemittance): CodRemittanceResource
    {
        return new CodRemittanceResource($codRemittance->load('deliveries'));
    }

    /**
     * Create a draft remittance
     *
     * From collected, not yet remitted deliveries of one courier. `expected_amount` is computed from them.
     */
    public function store(StoreCodRemittanceRequest $request): CodRemittanceResource
    {
        $data = $request->validated();
        $remittance = $this->remittances->create((int) $data['courier_id'], array_map('intval', $data['delivery_ids']), $data['reference'] ?? null, $data['notes'] ?? null);

        return new CodRemittanceResource($remittance->load('deliveries'));
    }

    /**
     * Post a remittance
     *
     * Stores the cash actually received and the difference. Posted remittances are immutable.
     */
    public function post(PostCodRemittanceRequest $request, CodRemittance $codRemittance): CodRemittanceResource
    {
        return new CodRemittanceResource($this->remittances->post($codRemittance, (int) $request->validated('received_amount'))->load('deliveries'));
    }

    /**
     * Delete a draft remittance
     *
     * Releases its deliveries. A posted remittance cannot be deleted (422).
     */
    public function destroy(CodRemittance $codRemittance): Response
    {
        $this->remittances->delete($codRemittance);

        return response()->noContent();
    }
}
