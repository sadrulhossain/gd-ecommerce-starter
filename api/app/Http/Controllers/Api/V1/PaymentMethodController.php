<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SavePaymentMethodRequest;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;

/**
 * Payment methods shown at checkout. Admin routes carry `can:payment-methods.view`; writes check their permission in
 * the FormRequest. A method needs a driver registered in config/payments.php. Credentials are write-only: responses
 * list the credential names that are set, never their values.
 */
class PaymentMethodController extends Controller
{
    /**
     * List payment methods
     *
     * `meta.drivers` lists the registered driver codes, to pick from when adding a method.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => PaymentMethod::query()->orderBy('sort')->orderBy('id')->get()->map(fn (PaymentMethod $m) => $this->present($m))->all(),
            'meta' => ['drivers' => array_keys((array) config('payments.drivers'))],
        ]);
    }

    /**
     * Add a payment method
     *
     * `code` must be a registered driver and not yet used.
     */
    public function store(SavePaymentMethodRequest $request): JsonResponse
    {
        $method = PaymentMethod::query()->create($request->validated())->refresh();

        return response()->json(['data' => $this->present($method)], 201);
    }

    /**
     * Update a payment method
     *
     * Disable a method to hide it from checkout; methods are not deleted. `credentials`: omit to keep, `{}` to clear,
     * an object to replace.
     */
    public function update(SavePaymentMethodRequest $request, PaymentMethod $paymentMethod): JsonResponse
    {
        $paymentMethod->update($request->validated());

        return response()->json(['data' => $this->present($paymentMethod)]);
    }

    /** @return array<string, mixed> */
    private function present(PaymentMethod $method): array
    {
        return [
            'id' => $method->id,
            'code' => $method->code,
            'name' => $method->name,
            'is_enabled' => $method->is_enabled,
            'sort' => $method->sort,
            'credential_keys' => array_keys($method->credentials ?? []),
            'driver_registered' => array_key_exists($method->code, (array) config('payments.drivers')),
        ];
    }
}
