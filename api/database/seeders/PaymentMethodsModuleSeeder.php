<?php

namespace Database\Seeders;

class PaymentMethodsModuleSeeder extends ModuleSeeder
{
    protected function permissions(): array
    {
        return ['payment-methods.view', 'payment-methods.create', 'payment-methods.update'];
    }

    protected function menus(): array
    {
        return [
            ['title' => 'Sales', 'sort' => 29, 'children' => [
                ['title' => 'Payment methods', 'permission' => 'payment-methods.view', 'path' => '/payment-methods', 'icon' => 'credit-card', 'sort' => 40],
            ]],
        ];
    }

    protected function grants(): array
    {
        return ['admin' => $this->permissions()];
    }
}
