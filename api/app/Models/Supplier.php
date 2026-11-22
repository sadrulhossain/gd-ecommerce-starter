<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A supplier. One with purchase orders can be deactivated but not deleted. */
#[Fillable(['name', 'code', 'contact_name', 'email', 'phone', 'address', 'tax_number', 'payment_terms', 'notes', 'is_active'])]
class Supplier extends Model
{
    use SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<PurchaseOrder, $this> */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
