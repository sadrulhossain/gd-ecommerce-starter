<?php

namespace App\Payments;

/**
 * One payment action. `amount` is integer minor units; `idempotencyKey` identifies the action.
 * `credentials` come from the encrypted payment_methods row and must never be logged.
 */
final class PaymentRequest
{
    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $referenceType,
        public readonly int $referenceId,
        public readonly int $amount,
        public readonly string $currency,
        #[\SensitiveParameter] public readonly array $credentials = [],
        public readonly array $meta = [],
    ) {}
}
