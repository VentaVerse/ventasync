<?php

// Permission labels use the RESOURCE name only ("Lazada", "Lazada Orders").
// The tier (View vs Manage) is communicated by the segmented pill UI on the
// user-group edit screen — don't bake "(view)" / "(manage)" into the label
// or it duplicates the segment text the operator is already reading.
return [
    'view_lazada' => [
        'label'       => 'Lazada',
        'description' => 'Read-only access to Lazada settings, products, and catalog data',
    ],
    'manage_lazada' => [
        'label'       => 'Lazada',
        'description' => 'Lazada store settings and product sync',
    ],
    'view_lazada_orders' => [
        'label'       => 'Lazada Orders',
        'description' => 'Read-only access to Lazada orders, returns, and shipping documents',
    ],
    'manage_lazada_orders' => [
        'label'       => 'Lazada Orders',
        'description' => 'View and sync orders from Lazada',
    ],
];
