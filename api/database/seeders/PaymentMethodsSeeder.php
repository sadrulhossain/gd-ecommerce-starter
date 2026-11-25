<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodsSeeder extends Seeder
{
    public function run(): void
    {
        // firstOrCreate: re-seeding never overwrites admin edits.
        PaymentMethod::query()->firstOrCreate(['code' => 'cod'], ['name' => 'Cash on delivery', 'sort' => 10]);
    }
}
