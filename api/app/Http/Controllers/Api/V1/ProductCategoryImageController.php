<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductCategoryImageRequest;
use App\Http\Resources\Api\V1\ProductCategoryResource;
use App\Models\ProductCategory;
use App\Services\MediaService;
use Illuminate\Support\Facades\Gate;

class ProductCategoryImageController extends Controller
{
    /**
     * Upload a shop category image
     *
     * Multipart field `image` (jpeg, png or webp). Replaces the current image.
     */
    public function store(StoreProductCategoryImageRequest $request, ProductCategory $productCategory, MediaService $media): ProductCategoryResource
    {
        $previous = $productCategory->image;

        $productCategory->update(['image' => $media->store($request->file('image'), 'product-categories')]);
        $media->delete($previous);

        return new ProductCategoryResource($productCategory);
    }

    /** Remove the shop category image */
    public function destroy(ProductCategory $productCategory, MediaService $media): ProductCategoryResource
    {
        Gate::authorize('update', $productCategory);

        $media->delete($productCategory->image);
        $productCategory->update(['image' => null]);

        return new ProductCategoryResource($productCategory);
    }
}
