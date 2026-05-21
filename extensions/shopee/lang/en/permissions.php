<?php

/*
|--------------------------------------------------------------------------
| Shopee Permission Display Strings
|--------------------------------------------------------------------------
|
| Resolved from core via the `ext-shopee::permissions.{key}` namespace,
| auto-registered by ExtensionProvider::bootTranslations(). Adding a new
| locale is one file: `extensions/shopee/lang/{locale}/permissions.php`.
|
*/

// Permission labels use the RESOURCE name only ("Shopee", "Shopee Orders").
// The tier (View vs Manage) is communicated by the segmented pill UI on the
// user-group edit screen — don't bake "(view)" / "(manage)" into the label
// or it duplicates the segment text the operator is already reading.
return [
    'view_shopee' => [
        'label'       => 'Shopee',
        'description' => 'Read-only access to Shopee settings, products, and catalog data',
    ],
    'manage_shopee' => [
        'label'       => 'Shopee',
        'description' => 'Shopee store settings and product sync',
    ],
    'view_shopee_orders' => [
        'label'       => 'Shopee Orders',
        'description' => 'Read-only access to Shopee orders, returns, and shipping documents',
    ],
    'manage_shopee_orders' => [
        'label'       => 'Shopee Orders',
        'description' => 'View and sync orders from Shopee',
    ],
];
