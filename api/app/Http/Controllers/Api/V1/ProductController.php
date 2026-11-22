<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListPublicProductsRequest;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Requests\Api\V1\UpdateProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Http\Resources\Api\V1\PublicProductResource;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\ProductPublishGuard;
use App\Services\SlugService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ProductController extends Controller
{
    /**
     * List shop products (public)
     *
     * Active products with at least one active variant, 12 per page (`per_page` up to 48).
     * Filters: `category` (slug, includes subcategories), `brand` (slug), `q` (name or SKU), `min_price` / `max_price`
     * (minor units, matches when any active variant is in range). `sort`: `newest` (default), `price_asc`, `price_desc`, `name`.
     * Each product carries `price_from`, its active variants and its images. No authentication.
     */
    public function publicIndex(ListPublicProductsRequest $request): AnonymousResourceCollection
    {
        $active = fn (Builder|Relation $query) => $query->where('is_active', true);
        $products = Product::query()->storefront()
            ->with(['category', 'brand' => $active, 'variants' => $active, 'images']);

        if ($slug = $request->query('category')) {
            $category = ProductCategory::query()->where('slug', $slug)->where('is_active', true)->first();
            $products->whereIn('category_id', $category?->selfAndDescendantIds() ?? []);
        }

        if ($slug = $request->query('brand')) {
            $products->whereHas('brand', fn (Builder $q) => $q->where('slug', $slug)->where('is_active', true));
        }

        if ($term = $request->query('q')) {
            $like = '%'.addcslashes((string) $term, '\\%_').'%';
            $products->where(fn (Builder $q) => $q->where('name', 'ilike', $like)
                ->orWhereHas('variants', fn (Builder $v) => $v->where('is_active', true)->where('sku', 'ilike', $like)));
        }

        if ($request->has('min_price') || $request->has('max_price')) {
            $products->whereHas('variants', function (Builder $v) use ($request): void {
                $v->where('is_active', true)
                    ->when($request->has('min_price'), fn (Builder $q) => $q->where('price', '>=', (int) $request->query('min_price')))
                    ->when($request->has('max_price'), fn (Builder $q) => $q->where('price', '<=', (int) $request->query('max_price')));
            });
        }

        match ($request->query('sort', 'newest')) {
            'price_asc' => $products->withMin(['variants as min_price' => $active], 'price')->orderBy('min_price')->orderBy('id'),
            'price_desc' => $products->withMin(['variants as min_price' => $active], 'price')->orderByDesc('min_price')->orderByDesc('id'),
            'name' => $products->orderBy('name')->orderBy('id'),
            default => $products->orderByDesc('id'),
        };

        return PublicProductResource::collection($products->paginate((int) $request->query('per_page', 12))->withQueryString());
    }

    /** Show a shop product by slug (public) */
    public function publicShow(string $slug): PublicProductResource
    {
        $active = fn (Builder|Relation $query) => $query->where('is_active', true);

        $product = Product::query()->storefront()->where('slug', $slug)
            ->with(['category', 'brand' => $active, 'variants' => $active, 'images'])
            ->firstOrFail();

        return new PublicProductResource($product);
    }

    /**
     * List products
     *
     * All statuses, newest first, 20 per page. Filters: `status`, `category_id`, `brand_id`, `q` (name or SKU).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Product::class);

        $products = Product::query()->with(['category', 'brand', 'variants', 'images'])
            ->when($request->query('status'), fn (Builder $q, $status) => $q->where('status', $status))
            ->when($request->query('category_id'), fn (Builder $q, $id) => $q->where('category_id', $id))
            ->when($request->query('brand_id'), fn (Builder $q, $id) => $q->where('brand_id', $id))
            ->when($request->query('q'), function (Builder $q, $term): void {
                $like = '%'.addcslashes((string) $term, '\\%_').'%';
                $q->where(fn (Builder $w) => $w->where('name', 'ilike', $like)
                    ->orWhereHas('variants', fn (Builder $v) => $v->where('sku', 'ilike', $like)));
            })
            ->orderByDesc('id')
            ->paginate(20)->withQueryString();

        return ProductResource::collection($products);
    }

    /** Show a product */
    public function show(Product $product): ProductResource
    {
        Gate::authorize('view', $product);

        return new ProductResource($product->load(['category', 'brand', 'variants', 'images']));
    }

    /**
     * Create a product
     *
     * Created as a draft together with its variants (`variants`, at least one). A product with `has_variants` false takes
     * exactly one variant without options; otherwise every variant needs the same option names, for example
     * `{"Color": "Red", "Size": "XL"}`. Money fields are integers in minor units. `description` is sanitised HTML;
     * `slug` is generated from `name` when omitted.
     */
    public function store(StoreProductRequest $request, SlugService $slugs): ProductResource
    {
        $data = $request->validated();
        $variants = $data['variants'];
        unset($data['variants']);
        $data['slug'] ??= $slugs->unique('products', $data['name']);

        $product = DB::transaction(function () use ($data, $variants): Product {
            $product = Product::query()->create($data);

            foreach (array_values($variants) as $i => $variant) {
                $product->variants()->create($variant + ['sort_order' => ($i + 1) * 10]);
            }

            return $product;
        });

        return new ProductResource($product->refresh()->load(['category', 'brand', 'variants', 'images']));
    }

    /** Update a product (not its variants, images or status) */
    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $product->update($request->validated());

        return new ProductResource($product->load(['category', 'brand', 'variants', 'images']));
    }

    /**
     * Delete a product
     *
     * Soft delete: the row, its variants and its slug are kept so orders can still resolve them.
     */
    public function destroy(Product $product): Response
    {
        Gate::authorize('delete', $product);

        $product->delete();

        return response()->noContent();
    }

    /**
     * Publish a product
     *
     * Needs an active variant, a primary image and an active category (422 otherwise). Also restores an archived product.
     */
    public function publish(Product $product, ProductPublishGuard $guard): ProductResource
    {
        Gate::authorize('publish', $product);

        $guard->assertPublishable($product);
        $product->forceFill(['status' => Product::ACTIVE])->save();

        return new ProductResource($product->load(['category', 'brand', 'variants', 'images']));
    }

    /** Archive a product (hidden from the shop, kept for history) */
    public function archive(Product $product): ProductResource
    {
        Gate::authorize('publish', $product);

        $product->forceFill(['status' => Product::ARCHIVED])->save();

        return new ProductResource($product->load(['category', 'brand', 'variants', 'images']));
    }

    /** Unpublish a product back to draft */
    public function unpublish(Product $product): ProductResource
    {
        Gate::authorize('publish', $product);

        $product->forceFill(['status' => Product::DRAFT])->save();

        return new ProductResource($product->load(['category', 'brand', 'variants', 'images']));
    }
}
