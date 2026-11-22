<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One sellable SKU. Amounts are integer minor units in `currency` (BDT); the Resource returns `{amount, currency}`.
 * `options` maps option name to value (`{"Color": "Red"}`) and is empty on a product's default variant.
 */
#[Fillable([
    'sku', 'barcode', 'name', 'options', 'price', 'compare_at_price', 'cost_price',
    'currency', 'weight_grams', 'is_active', 'sort_order',
])]
class ProductVariant extends Model
{
    use SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = ['currency' => 'BDT', 'is_active' => true, 'sort_order' => 0, 'options' => '{}'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'compare_at_price' => 'integer',
            'cost_price' => 'integer',
            'weight_grams' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Always stored as a JSON object (`{}` when empty, never `[]`), read back as an array.
     *
     * @return Attribute<array<string, string>, array<string, string>|null>
     */
    protected function options(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => $value === null ? [] : (array) json_decode($value, true),
            set: fn (?array $value): string => json_encode((object) ($value ?? [])),
        );
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
