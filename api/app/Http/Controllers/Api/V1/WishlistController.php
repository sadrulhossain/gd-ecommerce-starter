<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PublicProductResource;
use App\Models\Customer;
use App\Models\Product;
use App\Models\WishlistItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** A customer's wishlist (customer guard). Only storefront-visible products are listed. */
class WishlistController extends Controller
{
    /**
     * My wishlist
     *
     * Newest first. Products that are no longer sold are left out.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $products = Product::query()->storefront()
            ->whereIn('id', WishlistItem::query()->where('customer_id', $this->customer($request)->id)->select('product_id'))
            ->with(['category', 'brand', 'images', 'variants' => fn ($q) => $q->where('is_active', true)])
            ->orderByDesc('id')->get();

        return PublicProductResource::collection($products);
    }

    /**
     * Add to my wishlist
     *
     * Idempotent: adding twice keeps one entry. Only storefront-visible products can be added (404 otherwise).
     */
    public function store(Request $request, int $productId): Response
    {
        $product = Product::query()->storefront()->findOrFail($productId);
        WishlistItem::query()->firstOrCreate(['customer_id' => $this->customer($request)->id, 'product_id' => $product->id]);

        return response()->noContent();
    }

    /**
     * Remove from my wishlist
     */
    public function destroy(Request $request, int $productId): Response
    {
        WishlistItem::query()->where('customer_id', $this->customer($request)->id)->where('product_id', $productId)->delete();

        return response()->noContent();
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        return $customer;
    }
}
