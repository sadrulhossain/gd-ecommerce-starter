<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A shop category. A tree of at most MAX_DEPTH levels; products belong to exactly one. */
#[Fillable(['parent_id', 'name', 'slug', 'description', 'image', 'sort_order', 'is_active'])]
class ProductCategory extends Model
{
    public const MAX_DEPTH = 3;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<ProductCategory, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<ProductCategory, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    /** Level of this category: 1 for a root. */
    public function depth(): int
    {
        $depth = 1;
        $seen = [$this->id];
        $parentId = $this->parent_id;

        while ($parentId !== null && ! in_array($parentId, $seen, true)) {
            $seen[] = $parentId;
            $depth++;
            $parentId = self::query()->whereKey($parentId)->value('parent_id');
        }

        return $depth;
    }

    /** Levels in the subtree below this category (0 for a leaf). */
    public function height(): int
    {
        $height = 0;
        $level = [$this->id];

        while ($height < self::MAX_DEPTH) {
            $level = self::query()->whereIn('parent_id', $level)->pluck('id')->all();

            if ($level === []) {
                break;
            }

            $height++;
        }

        return $height;
    }

    /**
     * Ids of this category and all of its descendants.
     *
     * @return list<int>
     */
    public function selfAndDescendantIds(): array
    {
        $ids = [$this->id];
        $level = [$this->id];

        while ($level !== []) {
            $level = self::query()->whereIn('parent_id', $level)->whereNotIn('id', $ids)->pluck('id')->all();
            $ids = [...$ids, ...$level];
        }

        return $ids;
    }
}
