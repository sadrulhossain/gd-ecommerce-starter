<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/** Variants and images are part of the product: they use `products.update` (and `products.view` to read). */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('products.view');
    }

    public function view(User $user, Product $product): bool
    {
        return $user->checkPermissionTo('products.view');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('products.create');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->checkPermissionTo('products.update');
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->checkPermissionTo('products.delete');
    }

    /** Publish, archive and restore to the shop. */
    public function publish(User $user, Product $product): bool
    {
        return $user->checkPermissionTo('products.publish');
    }
}
