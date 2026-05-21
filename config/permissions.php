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
|   tiers    => array   — optional UI hint: ['view','manage'] when both
|                         view_X and manage_X siblings exist. Structural
|                         metadata only — the actual keyspace is the source
|                         of truth (PermissionHierarchy::tiersOf reads keys,
|                         not this field). The field lets the User Groups
|                         UI render the Off | View | Manage tri-state.
|
| Container permissions (e.g. `manage_marketplace_api`) declare themselves
| with no children; the children come from extension manifests that name
| this key as their `parent`. This lets the marketplace section grow when
| a new marketplace extension is installed, without editing core.
|
| Phase 4.5 — unified view/manage tier split for ALL non-container resources:
| every resource permission (products, categories, orders, users, etc.) has
| both view_X and manage_X siblings. Container parents stay single-tier —
| they're navigation grouping nodes, no routes attached.
|
*/

return [

    // ── Catalog ─────────────────────────────────────────────────
    'view_catalog'         => ['parent' => null,             'label' => 'Catalog',          'sort' => 10, 'tiers' => ['view', 'manage']],
    'manage_catalog'       => ['parent' => null,             'label' => 'Catalog',          'sort' => 10, 'tiers' => ['view', 'manage']],

    'view_products'        => ['parent' => 'manage_catalog', 'label' => 'View Products',        'tiers' => ['view', 'manage']],
    'manage_products'      => ['parent' => 'manage_catalog', 'label' => 'Manage Products',      'tiers' => ['view', 'manage']],

    'view_categories'      => ['parent' => 'manage_catalog', 'label' => 'View Categories',      'tiers' => ['view', 'manage']],
    'manage_categories'    => ['parent' => 'manage_catalog', 'label' => 'Manage Categories',    'tiers' => ['view', 'manage']],

    'view_manufacturers'   => ['parent' => 'manage_catalog', 'label' => 'View Manufacturers',   'tiers' => ['view', 'manage']],
    'manage_manufacturers' => ['parent' => 'manage_catalog', 'label' => 'Manage Manufacturers', 'tiers' => ['view', 'manage']],

    'view_options'         => ['parent' => 'manage_catalog', 'label' => 'View Options',         'tiers' => ['view', 'manage']],
    'manage_options'       => ['parent' => 'manage_catalog', 'label' => 'Manage Options',       'tiers' => ['view', 'manage']],

    // ── Sales ───────────────────────────────────────────────────
    'view_sales'            => ['parent' => null,           'label' => 'Sales',              'sort' => 20, 'tiers' => ['view', 'manage']],
    'manage_sales'          => ['parent' => null,           'label' => 'Sales',              'sort' => 20, 'tiers' => ['view', 'manage']],

    'view_orders'           => ['parent' => 'manage_sales', 'label' => 'View Orders',           'tiers' => ['view', 'manage']],
    'manage_orders'         => ['parent' => 'manage_sales', 'label' => 'Manage Orders',         'tiers' => ['view', 'manage']],

    'view_order_statuses'   => ['parent' => 'manage_sales', 'label' => 'View Order Statuses',   'tiers' => ['view', 'manage']],
    'manage_order_statuses' => ['parent' => 'manage_sales', 'label' => 'Manage Order Statuses', 'tiers' => ['view', 'manage']],

    // ── Marketplace API (container — extensions add their own children) ──
    'view_marketplace_api'   => ['parent' => null, 'label' => 'Marketplace API', 'sort' => 30, 'tiers' => ['view', 'manage']],
    'manage_marketplace_api' => ['parent' => null, 'label' => 'Marketplace API', 'sort' => 30, 'tiers' => ['view', 'manage']],

    // ── Settings ────────────────────────────────────────────────
    'view_settings'            => ['parent' => null,             'label' => 'Settings',          'sort' => 90, 'tiers' => ['view', 'manage']],
    'manage_settings'          => ['parent' => null,             'label' => 'Settings',          'sort' => 90, 'tiers' => ['view', 'manage']],

    'view_users'               => ['parent' => 'manage_settings', 'label' => 'View Users',               'tiers' => ['view', 'manage']],
    'manage_users'             => ['parent' => 'manage_settings', 'label' => 'Manage Users',             'tiers' => ['view', 'manage']],

    'view_user_groups'         => ['parent' => 'manage_settings', 'label' => 'View User Groups',         'tiers' => ['view', 'manage']],
    'manage_user_groups'       => ['parent' => 'manage_settings', 'label' => 'Manage User Groups',       'tiers' => ['view', 'manage']],

    'view_website_settings'    => ['parent' => 'manage_settings', 'label' => 'View Website Settings',    'tiers' => ['view', 'manage']],
    'manage_website_settings'  => ['parent' => 'manage_settings', 'label' => 'Manage Website Settings',  'tiers' => ['view', 'manage']],

    'view_error_log'           => ['parent' => 'manage_settings', 'label' => 'View Error Log',           'tiers' => ['view', 'manage']],
    'manage_error_log'         => ['parent' => 'manage_settings', 'label' => 'Manage Error Log',         'tiers' => ['view', 'manage']],

];
