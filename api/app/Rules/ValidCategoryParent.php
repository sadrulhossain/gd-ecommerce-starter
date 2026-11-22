<?php

namespace App\Rules;

use App\Models\ProductCategory;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A category parent must not create a cycle and must keep the tree within ProductCategory::MAX_DEPTH levels. */
class ValidCategoryParent implements ValidationRule
{
    public function __construct(private readonly ?ProductCategory $moving = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parent = $value === null ? null : ProductCategory::query()->find($value);

        if ($parent === null) {
            return;
        }

        if ($this->moving !== null && in_array($parent->id, $this->moving->selfAndDescendantIds(), true)) {
            $fail('A category cannot be moved under itself or one of its own subcategories.');

            return;
        }

        if ($parent->depth() + 1 + ($this->moving?->height() ?? 0) > ProductCategory::MAX_DEPTH) {
            $fail('Categories can be nested at most '.ProductCategory::MAX_DEPTH.' levels deep.');
        }
    }
}
