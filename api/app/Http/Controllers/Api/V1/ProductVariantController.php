<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductVariantRequest;
use App\Http\Requests\Api\V1\UpdateProductVariantRequest;
use App\Http\Resources\Api\V1\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProductVariantController extends Controller
{
    /** List the variants of a product */
    public function index(Product $product): AnonymousResourceCollection
    {
        Gate::authorize('view', $product);

        return ProductVariantResource::collection($product->variants()->get());
    }

    /**
     * Add a variant to a product
     *
     * Only for products with `has_variants` true. `options` must use the same option names as the other variants and
     * be unique within the product. Money fields are integers in minor units.
     */
    public function store(StoreProductVariantRequest $request, Product $product): ProductVariantResource
    {
        $variant = $product->variants()->create($request->validated());

        return new ProductVariantResource($variant->refresh());
    }

    /** Show a variant */
    public function show(ProductVariant $variant): ProductVariantResource
    {
        Gate::authorize('view', $variant->product);

        return new ProductVariantResource($variant);
    }

    /** Update a variant */
    public function update(UpdateProductVariantRequest $request, ProductVariant $variant): ProductVariantResource
    {
        $variant->update($request->validated());

        return new ProductVariantResource($variant);
    }

    /**
     * Delete a variant
     *
     * Soft delete. Refused (422) for the last variant of a product.
     */
    public function destroy(ProductVariant $variant): Response
    {
        Gate::authorize('update', $variant->product);

        if (! $variant->product->has_variants || $variant->product->variants()->count() <= 1) {
            throw ValidationException::withMessages(['variant' => 'A product keeps at least one variant.']);
        }

        $variant->delete();

        return response()->noContent();
    }
}
