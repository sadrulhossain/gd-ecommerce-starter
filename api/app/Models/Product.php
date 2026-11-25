<?php

namespace App\Models;

use App\Services\HtmlSanitizer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A sellable product. Everything sold is a variant: a product without options owns one default variant
 * (`has_variants` false). `status` only changes through the publish/archive endpoints.
 * `description` is rich-text HTML, sanitised on every write.
 */
#[Fillable([
    'category_id', 'brand_id', 'tax_rate_id', 'name', 'slug', 'short_description', 'description',
    'has_variants', 'seo_title', 'seo_description',
])]
class Product extends Model
{
    use SoftDeletes;

    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const ARCHIVED = 'archived';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::DRAFT];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['has_variants' => 'boolean'];
    }

    /** @return Attribute<string|null, string|null> */
    protected function description(): Attribute
    {
        return Attribute::set(fn (?string $html) => app(HtmlSanitizer::class)->clean($html));
    }

    /**
     * Visible in the shop: active, in an active category, with at least one active variant.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeStorefront(Builder $query): void
    {
        $query->where('status', self::ACTIVE)
            ->whereHas('category', fn (Builder $q) => $q->where('is_active', true))
            ->whereHas('variants', fn (Builder $q) => $q->where('is_active', true));
    }

    /** @return BelongsTo<ProductCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }
}
