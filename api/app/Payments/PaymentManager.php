<?php

namespace App\Payments;

use App\Models\PaymentEvent;
use App\Models\PaymentMethod;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * The only entry point for payments. Resolves the driver from config/payments.php, loads the encrypted
 * credentials, and records every event under its idempotency key: repeating a key returns the stored event.
 */
class PaymentManager
{
    public function __construct(private readonly Container $container) {}

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys((array) config('payments.drivers'));
    }

    public function driver(string $code): PaymentProvider
    {
        $class = config("payments.drivers.{$code}");
        if (! is_string($class)) {
            throw ValidationException::withMessages(['payment_method' => ['Unknown payment method.']]);
        }
        $provider = $this->container->make($class);
        assert($provider instanceof PaymentProvider);

        return $provider;
    }

    /** @param  array<string, mixed>  $meta */
    public function initiate(string $code, string $idempotencyKey, string $referenceType, int $referenceId, int $amount, array $meta = []): PaymentEvent
    {
        return $this->run('initiate', $code, $idempotencyKey, $referenceType, $referenceId, $amount, $meta);
    }

    /** @param  array<string, mixed>  $meta */
    public function refund(string $code, string $idempotencyKey, string $referenceType, int $referenceId, int $amount, array $meta = []): PaymentEvent
    {
        return $this->run('refund', $code, $idempotencyKey, $referenceType, $referenceId, $amount, $meta);
    }

    /** @param  array<string, mixed>  $meta */
    private function run(string $action, string $code, string $key, string $refType, int $refId, int $amount, array $meta): PaymentEvent
    {
        $existing = PaymentEvent::query()->where('idempotency_key', $key)->first();
        if ($existing) {
            return $existing;
        }
        if ($amount < 0) {
            throw ValidationException::withMessages(['amount' => ['The amount cannot be negative.']]);
        }

        $method = PaymentMethod::query()->where('code', $code)->first();
        if (! $method || ! $method->is_enabled) {
            throw ValidationException::withMessages(['payment_method' => ['This payment method is not available.']]);
        }

        $currency = (string) config('payments.currency');
        $request = new PaymentRequest($key, $refType, $refId, $amount, $currency, $method->credentials ?? [], $meta);
        $driver = $this->driver($code);
        $result = $action === 'refund' ? $driver->refund($request) : $driver->initiate($request);

        try {
            return PaymentEvent::query()->create([
                'idempotency_key' => $key,
                'payment_method' => $code,
                'action' => $action,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'amount' => $amount,
                'currency' => $currency,
                'status' => $result->status,
                'provider_reference' => $result->providerReference,
                'data' => $result->data,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent call with the same key won the race: return its event.
            return PaymentEvent::query()->where('idempotency_key', $key)->firstOrFail();
        }
    }
}
