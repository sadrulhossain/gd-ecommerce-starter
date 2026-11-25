<?php

namespace App\Payments;

/** Outcome of a provider call. Statuses: pending (e.g. cash on delivery), paid, failed, refunded. */
final class PaymentResult
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const REFUNDED = 'refunded';

    /** @param  array<string, mixed>  $data */
    public function __construct(
        public readonly string $status,
        public readonly ?string $providerReference = null,
        public readonly array $data = [],
    ) {}
}
