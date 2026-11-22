<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;

class BrandPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('brands.view');
    }

    public function view(User $user, Brand $brand): bool
    {
        return $user->checkPermissionTo('brands.view');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('brands.create');
    }

    public function update(User $user, Brand $brand): bool
    {
        return $user->checkPermissionTo('brands.update');
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $user->checkPermissionTo('brands.delete');
    }
}
