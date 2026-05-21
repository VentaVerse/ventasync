<?php

// Permission labels use the RESOURCE name only. The tier (View vs Manage) is
// communicated by the segmented pill UI on the user-group edit screen.
return [
    'view_opencart' => [
        'label'       => 'OpenCart',
        'description' => 'Read-only access to OpenCart store settings, product groups, and catalog data',
    ],
    'manage_opencart' => [
        'label'       => 'OpenCart',
        'description' => 'OpenCart store integration and product sync',
    ],
    'view_opencart_orders' => [
        'label'       => 'OpenCart Orders',
        'description' => 'Read-only access to OpenCart orders',
    ],
    'manage_opencart_orders' => [
        'label'       => 'OpenCart Orders',
        'description' => 'View and sync orders from OpenCart',
    ],
    'view_reviews' => [
        'label'       => 'Reviews',
        'description' => 'Read-only access to marketplace reviews',
    ],
    'manage_reviews' => [
        'label'       => 'Reviews',
        'description' => 'View marketplace reviews and manage review sync',
    ],
];
