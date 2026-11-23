<?php

namespace App\Policies;

use App\Models\GoodsReceipt;
use App\Models\User;

/** Lines are part of the receipt: they use `goods-receipts.update`. */
class GoodsReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('goods-receipts.view');
    }

    public function view(User $user, GoodsReceipt $receipt): bool
    {
        return $user->checkPermissionTo('goods-receipts.view');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('goods-receipts.create');
    }

    public function update(User $user, GoodsReceipt $receipt): bool
    {
        return $user->checkPermissionTo('goods-receipts.update');
    }

    public function delete(User $user, GoodsReceipt $receipt): bool
    {
        return $user->checkPermissionTo('goods-receipts.delete');
    }

    /** Post a draft: adds the stock. */
    public function post(User $user, GoodsReceipt $receipt): bool
    {
        return $user->checkPermissionTo('goods-receipts.post');
    }
}
