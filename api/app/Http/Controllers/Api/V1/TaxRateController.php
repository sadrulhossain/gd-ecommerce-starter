<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveTaxRateRequest;
use App\Models\Product;
use App\Models\TaxRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Tax rates. Admin routes carry `can:tax-rates.view|manage`. Prices are tax-exclusive. */
class TaxRateController extends Controller
{
    /**
     * List tax rates
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => TaxRate::query()->orderBy('name')->orderBy('id')->get()->map(fn (TaxRate $r) => $this->present($r))]);
    }

    /**
     * Create a tax rate
     *
     * The default rate applies to products without their own rate. Making a rate the default replaces the old one.
     */
    public function store(SaveTaxRateRequest $request): JsonResponse
    {
        $rate = DB::transaction(function () use ($request): TaxRate {
            $this->clearDefault($request);

            return TaxRate::query()->create($request->validated())->refresh();
        });

        return response()->json(['data' => $this->present($rate)], 201);
    }

    /**
     * Update a tax rate
     *
     * A new rate applies to later orders only; placed orders keep their snapshot.
     */
    public function update(SaveTaxRateRequest $request, TaxRate $taxRate): JsonResponse
    {
        DB::transaction(function () use ($request, $taxRate): void {
            $this->clearDefault($request, $taxRate->id);
            $taxRate->update($request->validated());
        });

        return response()->json(['data' => $this->present($taxRate)]);
    }

    /**
     * Delete a tax rate
     *
     * Refused (422) while a product uses it.
     */
    public function destroy(TaxRate $taxRate): Response
    {
        if (Product::query()->where('tax_rate_id', $taxRate->id)->exists()) {
            throw ValidationException::withMessages(['tax_rate' => 'Products use this tax rate. Deactivate it instead.']);
        }
        $taxRate->delete();

        return response()->noContent();
    }

    private function clearDefault(SaveTaxRateRequest $request, ?int $exceptId = null): void
    {
        if ($request->boolean('is_default')) {
            TaxRate::query()->where('is_default', true)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->update(['is_default' => false]);
        }
    }

    /** @return array<string, mixed> */
    private function present(TaxRate $rate): array
    {
        return ['id' => $rate->id, 'name' => $rate->name, 'rate_bp' => $rate->rate_bp, 'is_default' => $rate->is_default, 'is_active' => $rate->is_active];
    }
}
