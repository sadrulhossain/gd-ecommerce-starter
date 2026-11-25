<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One payment event, unique by idempotency key. Money is integer minor units plus currency. */
#[Fillable(['idempotency_key', 'payment_method', 'action', 'reference_type', 'reference_id', 'amount', 'currency', 'status', 'provider_reference', 'data'])]
class PaymentEvent extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'integer', 'reference_id' => 'integer', 'data' => 'array'];
    }
}
