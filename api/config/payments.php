<?php

use App\Payments\Drivers\CodProvider;

return [
    'currency' => 'BDT',

    // Registry: payment method code => driver class. A new PSO/PSP is one class plus one line here.
    'drivers' => [
        'cod' => CodProvider::class,
    ],
];
