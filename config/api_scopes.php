<?php

/*
|--------------------------------------------------------------------------
| External API Scopes
|--------------------------------------------------------------------------
|
| Catalog of resource:action scopes an API Application (see Settings → API)
| can be granted. The management UI renders one row per resource with a
| Read / Write checkbox; a checked box grants "{resource}:read" /
| "{resource}:write". Unchecked = no permission for that resource.
|
| These strings are stored as Sanctum token abilities and enforced by the
| `scope` middleware on each API route. v1 grants READ to the AI agent;
| WRITE scopes exist here but are not granted until the write phase.
|
*/

return [

    // resource key => ['label' => ..., 'actions' => [read|write], 'description' => ...]
    'products' => [
        'label'       => 'Products',
        'actions'     => ['read', 'write'],
        'description' => 'Catalog products incl. cost, price and per-channel markup.',
    ],

    'reports' => [
        'label'       => 'Sales Reports',
        'actions'     => ['read'], // reports are inherently read-only
        'description' => 'Aggregated sales: units, revenue, COGS, fees, net profit, margin.',
    ],

    'orders' => [
        'label'       => 'Orders',
        'actions'     => ['read', 'write'],
        'description' => 'Order headers, line items and status.',
    ],

    'inventory' => [
        'label'       => 'Inventory',
        'actions'     => ['read', 'write'],
        'description' => 'Stock levels and quantity adjustments.',
    ],

];
