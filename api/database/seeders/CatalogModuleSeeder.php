<?php

namespace Database\Seeders;

class CatalogModuleSeeder extends ModuleSeeder
{
    protected function permissions(): array
    {
        return [
            'product-categories.view', 'product-categories.create', 'product-categories.update', 'product-categories.delete',
            'brands.view', 'brands.create', 'brands.update', 'brands.delete',
            'products.view', 'products.create', 'products.update', 'products.delete', 'products.publish',
        ];
    }

    protected function menus(): array
    {
        return [
            ['title' => 'Catalog', 'sort' => 25, 'children' => [
                ['title' => 'Products', 'permission' => 'products.view', 'path' => '/products', 'icon' => 'package', 'sort' => 10],
                ['title' => 'Product categories', 'permission' => 'product-categories.view', 'path' => '/product-categories', 'icon' => 'folder-tree', 'sort' => 20],
                ['title' => 'Brands', 'permission' => 'brands.view', 'path' => '/brands', 'icon' => 'tag', 'sort' => 30],
            ]],
        ];
    }

    protected function grants(): array
    {
        return ['admin' => $this->permissions()];
    }
}
