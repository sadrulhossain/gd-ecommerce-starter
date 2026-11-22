<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSupplierRequest;
use App\Http\Requests\Api\V1\UpdateSupplierRequest;
use App\Http\Resources\Api\V1\SupplierResource;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SupplierController extends Controller
{
    /**
     * List suppliers
     *
     * By name, 20 per page. Filters: `q` (name, code or email), `is_active` (true or false).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Supplier::class);

        $suppliers = Supplier::query()
            ->when($request->query('q'), function (Builder $query, $term): void {
                $like = '%'.addcslashes((string) $term, '\\%_').'%';
                $query->where(fn (Builder $q) => $q->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like)->orWhere('email', 'ilike', $like));
            })
            ->when($request->has('is_active'), fn (Builder $query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')->orderBy('id')
            ->paginate(20)->withQueryString();

        return SupplierResource::collection($suppliers);
    }

    /** Show a supplier */
    public function show(Supplier $supplier): SupplierResource
    {
        Gate::authorize('view', $supplier);

        return new SupplierResource($supplier);
    }

    /** Create a supplier */
    public function store(StoreSupplierRequest $request): SupplierResource
    {
        return new SupplierResource(Supplier::query()->create($request->validated())->refresh());
    }

    /** Update a supplier (set `is_active` false to retire one that has purchase orders) */
    public function update(UpdateSupplierRequest $request, Supplier $supplier): SupplierResource
    {
        $supplier->update($request->validated());

        return new SupplierResource($supplier);
    }

    /**
     * Delete a supplier
     *
     * Refused (422) once it has purchase orders; deactivate it instead.
     */
    public function destroy(Supplier $supplier): Response
    {
        Gate::authorize('delete', $supplier);

        if ($supplier->purchaseOrders()->exists()) {
            throw ValidationException::withMessages(['supplier' => 'This supplier has purchase orders. Deactivate it instead.']);
        }

        $supplier->delete();

        return response()->noContent();
    }
}
