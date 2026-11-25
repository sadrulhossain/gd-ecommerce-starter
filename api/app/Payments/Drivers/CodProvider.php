<?php

namespace App\Payments\Drivers;

use App\Payments\PaymentProvider;
use App\Payments\PaymentRequest;
use App\Payments\PaymentResult;

/** Cash on delivery: nothing is charged online; the payment stays pending until cash is collected and reconciled. */
class CodProvider implements PaymentProvider
{
    public function code(): string
    {
        return 'cod';
    }

    public function initiate(PaymentRequest $request): PaymentResult
    {
        return new PaymentResult(PaymentResult::PENDING);
    }

    public function refund(PaymentRequest $request): PaymentResult
    {
        return new PaymentResult(PaymentResult::REFUNDED);
    }
}
