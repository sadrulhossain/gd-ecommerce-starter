<?php

namespace Database\Seeders;

class StockDocumentsModuleSeeder extends ModuleSeeder
{
    protected function permissions(): array
    {
        $names = [];

        foreach (['damages', 'supplier-returns', 'adjustments', 'stock-counts'] as $module) {
            foreach (['view', 'create', 'update', 'delete', 'post'] as $action) {
                $names[] = "{$module}.{$action}";
            }
        }

        return $names;
    }

    protected function menus(): array
    {
        return [
            ['title' => 'Stock control', 'sort' => 29, 'children' => [
                ['title' => 'Damages', 'permission' => 'damages.view', 'path' => '/damages', 'icon' => 'triangle-alert', 'sort' => 10],
                ['title' => 'Supplier returns', 'permission' => 'supplier-returns.view', 'path' => '/supplier-returns', 'icon' => 'undo-2', 'sort' => 20],
                ['title' => 'Adjustments', 'permission' => 'adjustments.view', 'path' => '/adjustments', 'icon' => 'scale', 'sort' => 30],
                ['title' => 'Stock counts', 'permission' => 'stock-counts.view', 'path' => '/stock-counts', 'icon' => 'clipboard-check', 'sort' => 40],
            ]],
        ];
    }

    protected function grants(): array
    {
        return ['admin' => $this->permissions()];
    }
}
