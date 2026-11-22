<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReorderProductImagesRequest;
use App\Http\Requests\Api\V1\StoreProductImageRequest;
use App\Http\Requests\Api\V1\UpdateProductImageRequest;
use App\Http\Resources\Api\V1\ProductImageResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProductImageController extends Controller
{
    /**
     * Add a product image
     *
     * Multipart field `image` (jpeg, png or webp), optional `alt` and `variant_id`. The first image becomes the primary one.
     */
    public function store(StoreProductImageRequest $request, Product $product, MediaService $media): ProductImageResource
    {
        $image = $product->images()->create([
            'path' => $media->store($request->file('image'), 'products'),
            'alt' => $request->validated('alt'),
            'variant_id' => $request->validated('variant_id'),
            'sort_order' => ((int) $product->images()->max('sort_order')) + 10,
            'is_primary' => ! $product->images()->exists(),
        ]);

        return new ProductImageResource($image->refresh());
    }

    /** Update an image: `alt`, `variant_id`, or `is_primary: true` to make it the primary image */
    public function update(UpdateProductImageRequest $request, ProductImage $image): ProductImageResource
    {
        DB::transaction(function () use ($request, $image): void {
            if ($request->boolean('is_primary')) {
                ProductImage::query()->where('product_id', $image->product_id)->update(['is_primary' => false]);
                $image->is_primary = true;
            }

            $image->fill($request->safe()->except('is_primary'))->save();
        });

        return new ProductImageResource($image);
    }

    /**
     * Delete an image
     *
     * The next image becomes primary when the primary one is removed. Refused (422) for the last image of an active product.
     */
    public function destroy(ProductImage $image, MediaService $media): Response
    {
        $product = $image->product;
        Gate::authorize('update', $product);

        if ($product->status === Product::ACTIVE && $product->images()->count() <= 1) {
            throw ValidationException::withMessages(['image' => 'An active product keeps at least one image. Unpublish it first.']);
        }

        DB::transaction(function () use ($image, $product): void {
            $image->delete();

            if ($image->is_primary) {
                $product->images()->first()?->update(['is_primary' => true]);
            }
        });

        $media->delete($image->path);

        return response()->noContent();
    }

    /** Reorder the images of a product (body `{ids: [...]}`, all ids must belong to it) */
    public function reorder(ReorderProductImagesRequest $request, Product $product): JsonResponse
    {
        $ids = $request->ids();

        if (ProductImage::query()->where('product_id', $product->id)->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['ids' => 'Every id must be an image of this product.']);
        }

        DB::transaction(function () use ($ids, $product): void {
            foreach ($ids as $i => $id) {
                ProductImage::query()->where('product_id', $product->id)->whereKey($id)->update(['sort_order' => ($i + 1) * 10]);
            }
        });

        return response()->json(['data' => ['reordered' => count($ids)]]);
    }
}
