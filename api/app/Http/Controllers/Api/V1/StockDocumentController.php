<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreStockDocumentRequest;
use App\Http\Requests\Api\V1\UpdateStockDocumentRequest;
use App\Http\Resources\Api\V1\StockDocumentResource;
use App\Models\StockDocument;
use App\Services\StockDocumentService;
use App\Services\StockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Damages (`/damages`), supplier returns (`/supplier-returns`), adjustments (`/adjustments`) and stock counts
 * (`/stock-counts`) share one controller; the route carries the document type. Each type has its own permissions
 * (`damages.*`, `supplier-returns.*`, `adjustments.*`, `stock-counts.*`). Draft, then posted; stock changes only on post.
 */
class StockDocumentController extends Controller
{
    /**
     * List documents
     *
     * Newest first, 20 per page, without lines. Filters: `status`, `q` (number).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $type = $this->type($request);
        Gate::authorize('viewAny', [StockDocument::class, $type]);

        $documents = StockDocument::query()->with('supplier')->where('type', $type)
            ->when($request->query('status'), fn (Builder $q, $status) => $q->where('status', $status))
            ->when($request->query('q'), fn (Builder $q, $term) => $q->where('number', 'ilike', '%'.addcslashes((string) $term, '\\%_').'%'))
            ->orderByDesc('id')
            ->paginate(20)->withQueryString();

        return StockDocumentResource::collection($documents);
    }

    /** Show a document with its lines */
    public function show(Request $request, StockDocument $stockDocument): StockDocumentResource
    {
        $this->own($request, $stockDocument);
        Gate::authorize('view', $stockDocument);

        return new StockDocumentResource($stockDocument->withDetails());
    }

    /**
     * Create a document (draft)
     *
     * Fields by type: damage `reason` (required); supplier return `supplier_id` (required) and `reason`; adjustment
     * `reason` (required). Optional `lines` (`variant_id`, `quantity`, optional `unit_cost` in minor units, default the
     * variant's cost price): damage and return quantities are positive units removed, an adjustment quantity is a signed
     * non-zero change, a stock count quantity is the counted amount (0 or more).
     */
    public function store(StoreStockDocumentRequest $request, StockDocumentService $service): StockDocumentResource
    {
        $type = $this->type($request);
        $data = $request->validated();
        $lines = $data['lines'] ?? [];
        unset($data['lines']);

        $document = DB::transaction(function () use ($request, $service, $type, $data, $lines): StockDocument {
            $document = new StockDocument($data);
            $document->type = $type;
            $document->number = StockDocument::nextNumber($type);
            $document->created_by = $request->user()->id;
            $document->save();

            $service->syncLines($document, $lines);

            return $document;
        });

        return new StockDocumentResource($document->refresh()->withDetails());
    }

    /** Update a draft: header fields, and `lines` (replaces all lines when sent) */
    public function update(UpdateStockDocumentRequest $request, StockDocument $stockDocument, StockDocumentService $service): StockDocumentResource
    {
        $this->own($request, $stockDocument);
        $stockDocument->assertDraft();

        $data = $request->validated();
        $lines = $data['lines'] ?? null;
        unset($data['lines']);

        DB::transaction(function () use ($stockDocument, $service, $data, $lines): void {
            $stockDocument->update($data);

            if ($lines !== null) {
                $service->syncLines($stockDocument, $lines);
            }
        });

        return new StockDocumentResource($stockDocument->refresh()->withDetails());
    }

    /** Delete a draft (posted documents are kept; correct them with a new document) */
    public function destroy(Request $request, StockDocument $stockDocument): Response
    {
        $this->own($request, $stockDocument);
        Gate::authorize('delete', $stockDocument);

        $stockDocument->assertDraft();
        $stockDocument->delete();

        return response()->noContent();
    }

    /**
     * Post a document
     *
     * Writes the stock ledger (one row per line) and makes the document immutable. Damage and return remove stock,
     * an adjustment applies its signed change, a count sets stock to the counted quantity and records the expected
     * quantity and variance per line. Needs at least one line; nothing is written if any line would take stock below zero.
     */
    public function post(Request $request, StockDocument $stockDocument, StockDocumentService $service, StockService $stock): StockDocumentResource
    {
        $this->own($request, $stockDocument);
        Gate::authorize('post', $stockDocument);

        $stockDocument->assertDraft();
        $posted = $service->post($stockDocument, $request->user()->id, $stock);

        return new StockDocumentResource($posted->withDetails());
    }

    private function type(Request $request): string
    {
        return (string) $request->route('type');
    }

    /** The URL's document type must match the document (404 otherwise). */
    private function own(Request $request, StockDocument $document): void
    {
        abort_unless($document->type === $this->type($request), 404);
    }
}
