<?php

namespace Database\Seeders;

class PromotionsModuleSeeder extends ModuleSeeder
{
    protected function permissions(): array
    {
        return [
            'tax-rates.view', 'tax-rates.manage',
            'coupons.view', 'coupons.create', 'coupons.update', 'coupons.delete',
            'reviews.view', 'reviews.moderate', 'reviews.delete',
        ];
    }

    protected function menus(): array
    {
        return [
            ['title' => 'Promotions', 'sort' => 32, 'children' => [
                ['title' => 'Coupons', 'permission' => 'coupons.view', 'path' => '/coupons', 'icon' => 'ticket', 'sort' => 10],
                ['title' => 'Tax rates', 'permission' => 'tax-rates.view', 'path' => '/tax-rates', 'icon' => 'percent', 'sort' => 20],
                ['title' => 'Reviews', 'permission' => 'reviews.view', 'path' => '/reviews', 'icon' => 'star', 'sort' => 30],
            ]],
        ];
    }

    protected function grants(): array
    {
        return ['admin' => $this->permissions()];
    }
}
