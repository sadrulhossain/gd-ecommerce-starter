<?php

return [
    // Flat shipping per order in minor units (BDT paisa). E7 replaces it with delivery zones.
    'shipping_flat' => 6000,

    // A cart lives in Redis (default cache store) for this many days after its last change.
    'cart_ttl_days' => 30,

    'max_cart_lines' => 50,
    'max_line_quantity' => 20,
];
