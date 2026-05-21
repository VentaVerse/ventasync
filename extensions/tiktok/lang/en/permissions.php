<?php

// Permission labels use the RESOURCE name only ("TikTok Shop", "TikTok Orders").
// The tier (View vs Manage) is communicated by the segmented pill UI on the
// user-group edit screen — don't bake "(view)" / "(manage)" into the label
// or it duplicates the segment text the operator is already reading.
return [
    'view_tiktok' => [
        'label'       => 'TikTok Shop',
        'description' => 'Read-only access to TikTok Shop settings, products, and catalog data',
    ],
    'manage_tiktok' => [
        'label'       => 'TikTok Shop',
        'description' => 'TikTok Shop settings and product sync',
    ],
    'view_tiktok_orders' => [
        'label'       => 'TikTok Orders',
        'description' => 'Read-only access to TikTok Shop orders and shipping documents',
    ],
    'manage_tiktok_orders' => [
        'label'       => 'TikTok Orders',
        'description' => 'View and sync orders from TikTok Shop',
    ],
];
