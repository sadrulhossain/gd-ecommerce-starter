<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreReviewRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Product reviews. Customers review a product they received (a delivered order); a review is public once
 * approved. Admin routes carry `can:reviews.view|moderate|delete`.
 */
class ReviewController extends Controller
{
    /**
     * Product reviews
     *
     * Approved reviews, newest first, 20 per page, with the average rating and count.
     */
    public function publicIndex(string $slug): JsonResponse
    {
        $product = Product::query()->storefront()->where('slug', $slug)->firstOrFail();
        $approved = Review::query()->where('product_id', $product->id)->where('status', Review::APPROVED);

        $page = (clone $approved)->with('customer:id,name')->orderByDesc('id')->paginate(20);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Review $r) => [
                'id' => $r->id, 'rating' => $r->rating, 'title' => $r->title, 'body' => $r->body,
                'author' => $r->customer->name, 'created_at' => $r->created_at,
            ])->all(),
            'summary' => ['count' => $approved->count(), 'average' => round((float) ($approved->avg('rating') ?? 0), 2)],
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * Review a product
     *
     * Needs a delivered order that contains the product (422 on `product` otherwise). One review per product;
     * it waits for moderation.
     */
    public function store(StoreReviewRequest $request, string $slug): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');
        $product = Product::query()->where('slug', $slug)->where('status', Product::ACTIVE)->firstOrFail();

        $orderId = Order::query()->where('customer_id', $customer->id)->where('status', Order::DELIVERED)
            ->whereHas('items', fn (Builder $q) => $q->whereIn('variant_id', $product->variants()->withTrashed()->select('id')))
            ->orderBy('id')->value('id');
        if ($orderId === null) {
            throw ValidationException::withMessages(['product' => ['You can review a product after it has been delivered to you.']]);
        }
        if (Review::query()->where('product_id', $product->id)->where('customer_id', $customer->id)->exists()) {
            throw ValidationException::withMessages(['product' => ['You have already reviewed this product.']]);
        }

        $review = new Review;
        $review->forceFill([
            'product_id' => $product->id, 'customer_id' => $customer->id, 'order_id' => $orderId,
            ...$request->safe()->only(['rating', 'title', 'body']),
        ])->save();

        return response()->json(['data' => $this->present($review)], 201);
    }

    /**
     * List reviews
     *
     * Newest first, 20 per page. Filters: `status`, `product_id`.
     */
    public function index(Request $request): JsonResponse
    {
        $page = Review::query()
            ->when($request->query('status'), fn (Builder $q, $v) => $q->where('status', $v))
            ->when($request->query('product_id'), fn (Builder $q, $v) => $q->where('product_id', $v))
            ->orderByDesc('id')->paginate(20)->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (Review $r) => $this->present($r))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * Approve a review
     */
    public function approve(Review $review): JsonResponse
    {
        return $this->moderate($review, Review::APPROVED);
    }

    /**
     * Reject a review
     */
    public function reject(Review $review): JsonResponse
    {
        return $this->moderate($review, Review::REJECTED);
    }

    /**
     * Delete a review
     */
    public function destroy(Review $review): Response
    {
        $review->delete();

        return response()->noContent();
    }

    private function moderate(Review $review, string $status): JsonResponse
    {
        $review->forceFill(['status' => $status, 'moderated_at' => now()])->save();

        return response()->json(['data' => $this->present($review)]);
    }

    /** @return array<string, mixed> */
    private function present(Review $review): array
    {
        return [
            'id' => $review->id, 'product_id' => $review->product_id, 'customer_id' => $review->customer_id,
            'order_id' => $review->order_id, 'rating' => $review->rating, 'title' => $review->title, 'body' => $review->body,
            'status' => $review->status, 'moderated_at' => $review->moderated_at, 'created_at' => $review->created_at,
        ];
    }
}
