<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

/** The personal-access client that issues customer tokens (provider `customers`). Created once, never overwritten. */
class CustomerClientSeeder extends Seeder
{
    public function run(): void
    {
        $exists = Passport::client()->newQuery()->where('name', 'Customers')->where('provider', 'customers')->exists();

        if (! $exists) {
            app(ClientRepository::class)->createPersonalAccessGrantClient('Customers', 'customers');
        }
    }
}
