<?php

namespace Database\Seeders;

class StockModuleSeeder extends ModuleSeeder
{
    protected function permissions(): array
    {
        return [
            'stock.view',
            'goods-receipts.view', 'goods-receipts.create', 'goods-receipts.update', 'goods-receipts.delete', 'goods-receipts.post',
        ];
    }

    protected function menus(): array
    {
        return [
            ['title' => 'Inventory', 'sort' => 28, 'children' => [
                ['title' => 'Stock', 'permission' => 'stock.view', 'path' => '/stock', 'icon' => 'boxes', 'sort' => 10],
                ['title' => 'Check-in', 'permission' => 'goods-receipts.view', 'path' => '/goods-receipts', 'icon' => 'package-check', 'sort' => 20],
            ]],
        ];
    }

    protected function grants(): array
    {
        return ['admin' => $this->permissions()];
    }
}
