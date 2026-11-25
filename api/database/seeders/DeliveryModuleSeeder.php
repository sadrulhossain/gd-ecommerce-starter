<?php

namespace Database\Seeders;

class DeliveryModuleSeeder extends ModuleSeeder
{
    protected function permissions(): array
    {
        return [
            'delivery.view', 'delivery.manage',
            'cod-remittances.view', 'cod-remittances.create', 'cod-remittances.post', 'cod-remittances.delete',
            'reports.view',
        ];
    }

    protected function menus(): array
    {
        return [
            ['title' => 'Delivery', 'sort' => 30, 'children' => [
                ['title' => 'Zones and couriers', 'permission' => 'delivery.view', 'path' => '/delivery', 'icon' => 'map-pin', 'sort' => 10],
                ['title' => 'COD remittances', 'permission' => 'cod-remittances.view', 'path' => '/cod-remittances', 'icon' => 'banknote', 'sort' => 20],
            ]],
            ['title' => 'Reports', 'sort' => 31, 'children' => [
                ['title' => 'Reports', 'permission' => 'reports.view', 'path' => '/reports', 'icon' => 'chart-bar', 'sort' => 10],
            ]],
        ];
    }

    protected function grants(): array
    {
        return ['admin' => $this->permissions()];
    }
}
