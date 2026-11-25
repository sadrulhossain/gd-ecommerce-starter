<?php

namespace App\Payments;

/** Every payment method implements this and is resolved through PaymentManager. */
interface PaymentProvider
{
    /** Registry code, equal to payment_methods.code. */
    public function code(): string;

    /** Start a payment. Call it only through PaymentManager::initiate(). */
    public function initiate(PaymentRequest $request): PaymentResult;

    /** Refund part or all of a payment. Call it only through PaymentManager::refund(). */
    public function refund(PaymentRequest $request): PaymentResult;
}
