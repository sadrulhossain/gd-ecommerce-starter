<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveCouponRequest;
use App\Models\Coupon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Discount codes. Admin routes carry `can:coupons.view|delete`; writes check their permission in the FormRequest.
 * Customers use a code through `?coupon_code=` on the cart and `coupon_code` at checkout.
 */
class CouponController extends Controller
{
    /**
     * List coupons
     *
     * Newest first, 20 per page. Filters: `q` (code), `is_active`.
     */
    public function index(Request $request): JsonResponse
    {
        $coupons = Coupon::query()
            ->when($request->query('q'), fn (Builder $q, $term) => $q->where('code', 'ilike', '%'.addcslashes((string) $term, '\\%_').'%'))
            ->when($request->has('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderByDesc('id')->paginate(20)->withQueryString();

        return response()->json([
            'data' => $coupons->getCollection()->map(fn (Coupon $c) => $this->present($c))->all(),
            'meta' => ['current_page' => $coupons->currentPage(), 'last_page' => $coupons->lastPage(), 'total' => $coupons->total()],
        ]);
    }

    /**
     * Show a coupon
     */
    public function show(Coupon $coupon): JsonResponse
    {
        return response()->json(['data' => $this->present($coupon)]);
    }

    /**
     * Create a coupon
     *
     * `percent`: `value` is basis points (1000 = 10%), optionally capped by `max_discount`. `fixed`: `value` is minor units.
     */
    public function store(SaveCouponRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->present(Coupon::query()->create($request->validated())->refresh())], 201);
    }

    /**
     * Update a coupon
     *
     * Changes apply to later orders only. `used_count` cannot be set.
     */
    public function update(SaveCouponRequest $request, Coupon $coupon): JsonResponse
    {
        $coupon->update($request->validated());

        return response()->json(['data' => $this->present($coupon)]);
    }

    /**
     * Delete a coupon
     *
     * Refused (422) once it has been redeemed; deactivate it instead.
     */
    public function destroy(Coupon $coupon): Response
    {
        if (DB::table('coupon_redemptions')->where('coupon_id', $coupon->id)->exists()) {
            throw ValidationException::withMessages(['coupon' => 'This coupon has been redeemed. Deactivate it instead.']);
        }
        $coupon->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function present(Coupon $coupon): array
    {
        $currency = (string) config('payments.currency');
        $money = fn (?int $amount): ?array => $amount === null ? null : ['amount' => $amount, 'currency' => $currency];

        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'type' => $coupon->type,
            'value' => $coupon->value,
            'min_subtotal' => $money($coupon->min_subtotal),
            'max_discount' => $money($coupon->max_discount),
            'starts_at' => $coupon->starts_at,
            'ends_at' => $coupon->ends_at,
            'usage_limit' => $coupon->usage_limit,
            'used_count' => $coupon->used_count,
            'is_active' => $coupon->is_active,
        ];
    }
}
