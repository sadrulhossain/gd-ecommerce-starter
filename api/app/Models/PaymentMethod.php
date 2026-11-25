<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/** A payment method shown at checkout. `code` matches a driver in config/payments.php; credentials are encrypted. */
#[Fillable(['code', 'name', 'is_enabled', 'sort', 'credentials'])]
#[Hidden(['credentials'])]
class PaymentMethod extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['is_enabled' => true, 'sort' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'credentials' => 'encrypted:array'];
    }
}
