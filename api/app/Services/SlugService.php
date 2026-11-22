<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SlugService
{
    /**
     * A slug for `$name` that is not yet used in `$table` (soft-deleted rows count as used):
     * `red-shirt`, then `red-shirt-2`, `red-shirt-3`...
     */
    public function unique(string $table, string $name, string $column = 'slug'): string
    {
        $base = Str::limit(Str::slug($name) ?: 'item', 140, '');
        $slug = $base;

        for ($i = 2; DB::table($table)->where($column, $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
