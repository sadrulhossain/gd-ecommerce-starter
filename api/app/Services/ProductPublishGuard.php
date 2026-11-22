<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

/** The rules a product must meet before it may go live in the shop. */
class ProductPublishGuard
{
    /** @throws ValidationException */
    public function assertPublishable(Product $product): void
    {
        $problems = [];

        if (! $product->variants()->where('is_active', true)->exists()) {
            $problems[] = 'The product needs at least one active variant.';
        }

        if (! $product->images()->where('is_primary', true)->exists()) {
            $problems[] = 'The product needs a primary image.';
        }

        if (! $product->category()->where('is_active', true)->exists()) {
            $problems[] = 'The product category must be active.';
        }

        if ($problems !== []) {
            throw ValidationException::withMessages(['status' => $problems]);
        }
    }
}
