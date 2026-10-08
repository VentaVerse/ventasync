<?php

return [

    'products' => [
        'label'       => 'Products',
        'actions'     => ['read', 'write'],
        'description' => 'Catalog products incl. cost, price and per-channel markup.',
        'mcp_write' => true,
    ],

    'orders' => [
        'label'       => 'Orders',
        'actions'     => ['read', 'write'],
        'description' => 'Order headers, line items and status, and customer payments on accounts receivable.',
        'mcp_write' => true,
        'write_label' => 'Customer payments',
    ],

    'inventory' => [
        'label'       => 'Stock',
        'actions'     => ['read', 'write'],
        'description' => 'Stock levels and quantity adjustments.',
        'mcp_write' => true,
    ],

];
