<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreBrandRequest;
use App\Http\Requests\Api\V1\UpdateBrandRequest;
use App\Http\Resources\Api\V1\BrandResource;
use App\Models\Brand;
use App\Services\MediaService;
use App\Services\SlugService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class BrandController extends Controller
{
    /**
     * List brands (public)
     *
     * Active brands by name. No authentication.
     */
    public function publicIndex(): AnonymousResourceCollection
    {
        return BrandResource::collection(Brand::query()->where('is_active', true)->orderBy('name')->get());
    }

    /** List brands */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Brand::class);

        return BrandResource::collection(Brand::query()->orderBy('name')->get());
    }

    /** Show a brand */
    public function show(Brand $brand): BrandResource
    {
        Gate::authorize('view', $brand);

        return new BrandResource($brand);
    }

    /** Create a brand (`slug` is generated from `name` when omitted) */
    public function store(StoreBrandRequest $request, SlugService $slugs): BrandResource
    {
        $data = $request->validated();
        $data['slug'] ??= $slugs->unique('brands', $data['name']);

        return new BrandResource(Brand::query()->create($data)->refresh());
    }

    /** Update a brand */
    public function update(UpdateBrandRequest $request, Brand $brand): BrandResource
    {
        $brand->update($request->validated());

        return new BrandResource($brand);
    }

    /**
     * Delete a brand
     *
     * Its products are kept and become brandless.
     */
    public function destroy(Brand $brand, MediaService $media): Response
    {
        Gate::authorize('delete', $brand);

        $media->delete($brand->image);
        $brand->delete();

        return response()->noContent();
    }
}
