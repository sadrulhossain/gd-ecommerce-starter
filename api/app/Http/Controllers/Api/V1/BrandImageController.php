<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreBrandImageRequest;
use App\Http\Resources\Api\V1\BrandResource;
use App\Models\Brand;
use App\Services\MediaService;
use Illuminate\Support\Facades\Gate;

class BrandImageController extends Controller
{
    /**
     * Upload a brand logo
     *
     * Multipart field `image` (jpeg, png or webp). Replaces the current logo.
     */
    public function store(StoreBrandImageRequest $request, Brand $brand, MediaService $media): BrandResource
    {
        $previous = $brand->image;

        $brand->update(['image' => $media->store($request->file('image'), 'brands')]);
        $media->delete($previous);

        return new BrandResource($brand);
    }

    /** Remove the brand logo */
    public function destroy(Brand $brand, MediaService $media): BrandResource
    {
        Gate::authorize('update', $brand);

        $media->delete($brand->image);
        $brand->update(['image' => null]);

        return new BrandResource($brand);
    }
}
