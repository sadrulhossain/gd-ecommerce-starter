<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shop view of a product: only active variants, never `cost_price`, no status or SEO bookkeeping.
 * `price_from` is the lowest active variant price.
 *
 * @mixin Product
 */
class PublicProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ProductVariant|null $cheapest */
        $cheapest = $this->relationLoaded('variants') ? $this->variants->sortBy('price')->first() : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'has_variants' => $this->has_variants,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'category' => new ProductCategoryResource($this->whenLoaded('category')),
            'brand' => new BrandResource($this->whenLoaded('brand')),
            'price_from' => $cheapest === null ? null : ['amount' => $cheapest->price, 'currency' => $cheapest->currency],
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn (ProductVariant $variant) => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'name' => $variant->name,
                'options' => (object) $variant->options,
                'price' => ['amount' => $variant->price, 'currency' => $variant->currency],
                'compare_at_price' => $variant->compare_at_price === null ? null : ['amount' => $variant->compare_at_price, 'currency' => $variant->currency],
                'weight_grams' => $variant->weight_grams,
            ])->values()),
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
        ];
    }
}
