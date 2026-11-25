<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A tax rate in basis points (100 = 1%). Prices are tax-exclusive; tax is added per order line. */
#[Fillable(['name', 'rate_bp', 'is_default', 'is_active'])]
class TaxRate extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['is_default' => false, 'is_active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['rate_bp' => 'integer', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }
}
