<?php

return [

    'products' => [
        'label'       => 'Products',
        'actions'     => ['read', 'write'],
        'description' => 'Catalog products incl. cost, price and per-channel markup.',
        'assistant_write' => true,
    ],

    'orders' => [
        'label'       => 'Orders',
        'actions'     => ['read', 'write'],
        'description' => 'Order headers, line items and status, and customer payments on accounts receivable.',
        'assistant_write' => true,
        'write_label' => 'Customer payments',
    ],

    'inventory' => [
        'label'       => 'Stock',
        'actions'     => ['read', 'write'],
        'description' => 'Stock levels and quantity adjustments.',
        'assistant_write' => true,
    ],

];
