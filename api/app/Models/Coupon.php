<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A discount code. `percent`: `value` is basis points (1000 = 10%), capped by `max_discount`.
 * `fixed`: `value` is minor units. `used_count` is written only by CouponService.
 *
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 */
#[Fillable(['code', 'type', 'value', 'min_subtotal', 'max_discount', 'starts_at', 'ends_at', 'usage_limit', 'is_active'])]
class Coupon extends Model
{
    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true, 'min_subtotal' => 0, 'used_count' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'min_subtotal' => 'integer',
            'max_discount' => 'integer',
            'usage_limit' => 'integer',
            'used_count' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = strtoupper(trim($value));
    }
}
