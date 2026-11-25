<?php

namespace Database\Seeders;

class OrdersModuleSeeder extends ModuleSeeder
{
    protected function permissions(): array
    {
        return ['orders.view', 'orders.update', 'orders.cancel'];
    }

    protected function menus(): array
    {
        return [
            ['title' => 'Sales', 'sort' => 29, 'children' => [
                ['title' => 'Orders', 'permission' => 'orders.view', 'path' => '/orders', 'icon' => 'shopping-cart', 'sort' => 10],
            ]],
        ];
    }

    protected function grants(): array
    {
        return ['admin' => $this->permissions()];
    }
}
