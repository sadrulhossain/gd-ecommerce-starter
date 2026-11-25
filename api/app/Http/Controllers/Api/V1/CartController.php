<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddCartItemRequest;
use App\Http\Requests\Api\V1\CheckoutRequest;
use App\Http\Requests\Api\V1\UpdateCartItemRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Customer;
use App\Services\CartService;
use App\Services\OrderService;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** Public cart and checkout. A cart is a Redis list of variant ids and quantities behind an unguessable token. */
class CartController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
        private readonly PricingService $pricing,
        private readonly OrderService $orders,
    ) {}

    /**
     * Create a cart
     *
     * Returns the cart token the client must keep and send on every cart call.
     */
    public function store(): JsonResponse
    {
        $token = $this->carts->create();

        return response()->json(['data' => ['token' => $token] + $this->view($token)], 201);
    }

    /**
     * Show a cart
     *
     * Lines are priced by the server on every read; `available` and `issue` flag lines that cannot be sold now.
     */
    public function show(string $token): JsonResponse
    {
        return response()->json(['data' => ['token' => $token] + $this->view($this->known($token))]);
    }

    /**
     * Add to a cart
     *
     * Adds the quantity to the variant's line. Only sellable variants can be added.
     */
    public function addItem(AddCartItemRequest $request, string $token): JsonResponse
    {
        $this->known($token);
        $variantId = (int) $request->validated('variant_id');
        $quantity = ($this->carts->items($token)[$variantId] ?? 0) + (int) $request->validated('quantity');
        $quantity = min($quantity, (int) config('shop.max_line_quantity'));

        $this->assertCanAdd($token, $variantId, $quantity);
        $this->carts->set($token, $variantId, $quantity);

        return response()->json(['data' => ['token' => $token] + $this->view($token)]);
    }

    /**
     * Set a line quantity
     *
     * A quantity of 0 removes the line.
     */
    public function updateItem(UpdateCartItemRequest $request, string $token, int $variantId): JsonResponse
    {
        $this->known($token);
        $quantity = (int) $request->validated('quantity');
        if ($quantity > 0) {
            $this->assertCanAdd($token, $variantId, $quantity);
        }
        $this->carts->set($token, $variantId, $quantity);

        return response()->json(['data' => ['token' => $token] + $this->view($token)]);
    }

    /**
     * Remove a line
     */
    public function removeItem(string $token, int $variantId): Response
    {
        $this->carts->set($this->known($token), $variantId, 0);

        return response()->noContent();
    }

    /**
     * Check out
     *
     * Turns the cart into a pending order at server-side prices and empties the cart. A guest keeps the
     * returned `lookup_token` to read the order; a signed-in customer (Bearer token) also finds it in their orders.
     */
    public function checkout(CheckoutRequest $request, string $token): JsonResponse
    {
        $items = $this->carts->items($this->known($token));
        if ($items === []) {
            throw ValidationException::withMessages(['items' => ['The cart is empty.']]);
        }

        $customer = Auth::guard('customer')->user();
        $order = $this->orders->place($items, $request->validated(), $customer instanceof Customer ? $customer : null);
        $this->carts->clear($token);

        return (new OrderResource($order))->additional(['lookup_token' => $order->lookup_token])->response()->setStatusCode(201);
    }

    private function known(string $token): string
    {
        abort_unless($this->carts->exists($token), 404);

        return $token;
    }

    /** Reject a line that cannot be sold at this quantity (inactive, unavailable, not enough stock). */
    private function assertCanAdd(string $token, int $variantId, int $quantity): void
    {
        $quote = $this->pricing->quote([$variantId => $quantity]);
        if (! $quote['valid']) {
            throw ValidationException::withMessages(['quantity' => [$quote['lines'][0]['issue'] ?? 'This item cannot be added.']]);
        }
    }

    /** @return array<string, mixed> */
    private function view(string $token): array
    {
        $quote = $this->pricing->quote($this->carts->items($token));
        $money = fn (int $amount): array => ['amount' => $amount, 'currency' => $quote['currency']];

        return [
            'items' => array_map(fn (array $line): array => isset($line['sku']) ? [
                'variant_id' => $line['variant_id'],
                'sku' => $line['sku'],
                'name' => $line['name'],
                'options' => (object) $line['options'],
                'quantity' => $line['quantity'],
                'unit_price' => $money($line['unit_price']),
                'line_total' => $money($line['line_total']),
                'available' => $line['available'],
                'issue' => $line['issue'],
            ] : $line, $quote['lines']),
            'subtotal' => $money($quote['subtotal']),
            'shipping_total' => $money($quote['shipping_total']),
            'tax_total' => $money($quote['tax_total']),
            'discount_total' => $money($quote['discount_total']),
            'grand_total' => $money($quote['grand_total']),
            'can_checkout' => $quote['valid'],
        ];
    }
}
