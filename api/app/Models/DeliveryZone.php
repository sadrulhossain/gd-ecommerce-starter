<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A delivery zone with its shipping fee in integer minor units (BDT). */
#[Fillable(['name', 'shipping_fee', 'is_active', 'sort'])]
class DeliveryZone extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true, 'sort' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['shipping_fee' => 'integer', 'is_active' => 'boolean', 'sort' => 'integer'];
    }
}
