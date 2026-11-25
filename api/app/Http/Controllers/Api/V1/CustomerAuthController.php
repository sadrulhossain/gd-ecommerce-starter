<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginCustomerRequest;
use App\Http\Requests\Api\V1\RegisterCustomerRequest;
use App\Http\Resources\Api\V1\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Token;

/** Storefront customer accounts on the separate `customer` guard. */
class CustomerAuthController extends Controller
{
    /**
     * Register
     *
     * Creates the account and returns a Bearer token for the `customer` guard.
     */
    public function register(RegisterCustomerRequest $request): JsonResponse
    {
        $customer = Customer::query()->create($request->validated());

        return $this->tokenResponse($customer, 201);
    }

    /**
     * Sign in
     */
    public function login(LoginCustomerRequest $request): JsonResponse
    {
        $customer = Customer::query()->where('email', $request->validated('email'))->first();
        if (! $customer || ! Hash::check((string) $request->validated('password'), $customer->password)) {
            throw ValidationException::withMessages(['email' => ['These credentials do not match.']]);
        }

        return $this->tokenResponse($customer, 200);
    }

    /**
     * Current customer
     */
    public function me(Request $request): CustomerResource
    {
        return new CustomerResource($request->user('customer'));
    }

    /**
     * Sign out
     *
     * Revokes the current token.
     */
    public function logout(Request $request): Response
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');
        /** @var Token $token */
        $token = $customer->token();
        $token->revoke();

        return response()->noContent();
    }

    private function tokenResponse(Customer $customer, int $status): JsonResponse
    {
        return (new CustomerResource($customer))
            ->additional(['token' => $customer->createToken('customer')->accessToken])
            ->response()->setStatusCode($status);
    }
}
