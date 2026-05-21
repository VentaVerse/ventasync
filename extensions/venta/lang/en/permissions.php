<?php

// Permission labels use the RESOURCE name only ("Venta", "Venta Orders").
// The tier (View vs Manage) is communicated by the segmented pill UI on the
// user-group edit screen — don't bake "(view)" / "(manage)" into the label
// or it duplicates the segment text the operator is already reading.
return [
    'view_venta' => [
        'label'       => 'Venta',
        'description' => 'Read-only access to Venta settings, product groups, and catalog data',
    ],
    'manage_venta' => [
        'label'       => 'Venta',
        'description' => 'Venta store settings, product groups, and product sync',
    ],
    'view_venta_orders' => [
        'label'       => 'Venta Orders',
        'description' => 'Read-only access to Venta orders and shipping documents',
    ],
    'manage_venta_orders' => [
        'label'       => 'Venta Orders',
        'description' => 'View and sync orders from Venta stores',
    ],
];
