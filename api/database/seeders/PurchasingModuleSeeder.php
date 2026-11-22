<?php

namespace Database\Seeders;

class PurchasingModuleSeeder extends ModuleSeeder
{
    protected function permissions(): array
    {
        return [
            'suppliers.view', 'suppliers.create', 'suppliers.update', 'suppliers.delete',
            'purchase-orders.view', 'purchase-orders.create', 'purchase-orders.update', 'purchase-orders.delete',
            'purchase-orders.order', 'purchase-orders.cancel',
        ];
    }

    protected function menus(): array
    {
        return [
            ['title' => 'Purchasing', 'sort' => 27, 'children' => [
                ['title' => 'Purchase orders', 'permission' => 'purchase-orders.view', 'path' => '/purchase-orders', 'icon' => 'clipboard-list', 'sort' => 10],
                ['title' => 'Suppliers', 'permission' => 'suppliers.view', 'path' => '/suppliers', 'icon' => 'truck', 'sort' => 20],
            ]],
        ];
    }

    protected function grants(): array
    {
        return ['admin' => $this->permissions()];
    }
}
