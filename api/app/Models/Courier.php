<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A delivery company or rider. One with deliveries can be deactivated, not deleted. */
#[Fillable(['name', 'phone', 'is_active'])]
class Courier extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
