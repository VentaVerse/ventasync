<?php

/*
|--------------------------------------------------------------------------
| Core Permission Hierarchy
|--------------------------------------------------------------------------
|
| The permission tree used by the User Groups UI and User::hasPermission()
| expansion. Core declares its own groups here; extensions contribute via
| their extension.json manifests (each manifest permission can specify
| `parent` and `subgroup` — the PermissionHierarchy service merges both
| sources at runtime).
|
| Schema:
|   key      => string  — the permission key (matches DB permissions.key)
|   parent   => ?string — parent permission key, or null for top-level groups
|   label    => string  — human-readable label for the User Groups UI
|   sort     => int     — display order for top-level groups (lower = earlier)
|
| Container permissions (e.g. `manage_marketplace_api`) declare themselves
| with no children; the children come from extension manifests that name
| this key as their `parent`. This lets the marketplace section grow when
| a new marketplace extension is installed, without editing core.
|
*/

return [

    // ── Catalog ─────────────────────────────────────────────────
    'manage_catalog'       => ['parent' => null,             'label' => 'Catalog',          'sort' => 10],
    'manage_products'      => ['parent' => 'manage_catalog', 'label' => 'Manage Products'],
    'manage_categories'    => ['parent' => 'manage_catalog', 'label' => 'Manage Categories'],
    'manage_manufacturers' => ['parent' => 'manage_catalog', 'label' => 'Manage Manufacturers'],
    'manage_options'       => ['parent' => 'manage_catalog', 'label' => 'Manage Options'],

    // ── Sales ───────────────────────────────────────────────────
    'manage_sales'          => ['parent' => null,           'label' => 'Sales',              'sort' => 20],
    'manage_orders'         => ['parent' => 'manage_sales', 'label' => 'Manage Orders'],
    'manage_order_statuses' => ['parent' => 'manage_sales', 'label' => 'Manage Order Statuses'],

    // ── Marketplace API (container — extensions add their own children) ──
    'manage_marketplace_api' => ['parent' => null, 'label' => 'Marketplace API', 'sort' => 30],

    // ── Settings ────────────────────────────────────────────────
    'manage_settings'          => ['parent' => null,             'label' => 'Settings',          'sort' => 90],
    'manage_users'             => ['parent' => 'manage_settings', 'label' => 'Manage Users'],
    'manage_user_groups'       => ['parent' => 'manage_settings', 'label' => 'Manage User Groups'],
    'manage_website_settings'  => ['parent' => 'manage_settings', 'label' => 'Manage Website Settings'],
    'manage_error_log'         => ['parent' => 'manage_settings', 'label' => 'Manage Error Log'],

];
