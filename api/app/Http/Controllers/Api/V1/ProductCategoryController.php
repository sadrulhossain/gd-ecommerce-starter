<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReorderProductCategoriesRequest;
use App\Http\Requests\Api\V1\StoreProductCategoryRequest;
use App\Http\Requests\Api\V1\UpdateProductCategoryRequest;
use App\Http\Resources\Api\V1\ProductCategoryResource;
use App\Models\ProductCategory;
use App\Services\MediaService;
use App\Services\SlugService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProductCategoryController extends Controller
{
    /**
     * List shop categories (public)
     *
     * The active category tree (up to three levels, `children` nested), ordered by `sort_order`. No authentication.
     */
    public function publicIndex(): AnonymousResourceCollection
    {
        $active = fn (Builder|Relation $query) => $query->where('is_active', true);

        $tree = ProductCategory::query()->whereNull('parent_id')->where('is_active', true)
            ->with(['children' => $active, 'children.children' => $active])
            ->orderBy('sort_order')->orderBy('id')->get();

        return ProductCategoryResource::collection($tree);
    }

    /**
     * List shop categories
     *
     * Every category as a flat list (`parent_id` links the tree), ordered by parent and `sort_order`.
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ProductCategory::class);

        return ProductCategoryResource::collection(
            ProductCategory::query()->orderBy('parent_id')->orderBy('sort_order')->orderBy('id')->get(),
        );
    }

    /** Show a shop category */
    public function show(ProductCategory $productCategory): ProductCategoryResource
    {
        Gate::authorize('view', $productCategory);

        return new ProductCategoryResource($productCategory);
    }

    /**
     * Create a shop category
     *
     * `slug` is generated from `name` when omitted. Nesting is limited to three levels.
     */
    public function store(StoreProductCategoryRequest $request, SlugService $slugs): ProductCategoryResource
    {
        $data = $request->validated();
        $data['slug'] ??= $slugs->unique('product_categories', $data['name']);

        return new ProductCategoryResource(ProductCategory::query()->create($data)->refresh());
    }

    /** Update a shop category */
    public function update(UpdateProductCategoryRequest $request, ProductCategory $productCategory): ProductCategoryResource
    {
        $productCategory->update($request->validated());

        return new ProductCategoryResource($productCategory);
    }

    /**
     * Delete a shop category
     *
     * Refused (422) while it has subcategories or products.
     */
    public function destroy(ProductCategory $productCategory, MediaService $media): Response
    {
        Gate::authorize('delete', $productCategory);

        if ($productCategory->children()->exists() || $productCategory->products()->withTrashed()->exists()) {
            throw ValidationException::withMessages(['category' => 'Move or delete its subcategories and products first.']);
        }

        $media->delete($productCategory->image);
        $productCategory->delete();

        return response()->noContent();
    }

    /** Reorder shop categories (body `{ids: [...]}`) */
    public function reorder(ReorderProductCategoriesRequest $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            foreach ($request->ids() as $i => $id) {
                ProductCategory::query()->whereKey($id)->update(['sort_order' => ($i + 1) * 10]);
            }
        });

        return response()->json(['data' => ['reordered' => count($request->ids())]]);
    }
}
