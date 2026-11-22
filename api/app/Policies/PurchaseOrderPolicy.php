<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;

/** Lines are part of the order: they use `purchase-orders.update`. */
class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('purchase-orders.view');
    }

    public function view(User $user, PurchaseOrder $order): bool
    {
        return $user->checkPermissionTo('purchase-orders.view');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('purchase-orders.create');
    }

    public function update(User $user, PurchaseOrder $order): bool
    {
        return $user->checkPermissionTo('purchase-orders.update');
    }

    public function delete(User $user, PurchaseOrder $order): bool
    {
        return $user->checkPermissionTo('purchase-orders.delete');
    }

    /** Post a draft as an order. */
    public function order(User $user, PurchaseOrder $order): bool
    {
        return $user->checkPermissionTo('purchase-orders.order');
    }

    public function cancel(User $user, PurchaseOrder $order): bool
    {
        return $user->checkPermissionTo('purchase-orders.cancel');
    }
}
