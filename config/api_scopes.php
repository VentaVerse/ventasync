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

    // CORE-owned scopes only (catalog resources: products, orders, inventory).
    // Extensions contribute their own scopes via extension.json "api_scopes";
    // the two are merged by App\Services\ApiScopeRegistry. Do NOT add
    // extension-specific resources here — keep core decoupled from modules.
    //
    // resource key => ['label' => ..., 'actions' => [read|write], 'description' => ...]
    // 'assistant_write' => true offers the write on the AI Tools page; leave
    // it off for a write no assistant tool stands behind. 'write_label'
    // names the write there when it is narrower than the area.

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
        // The only order write is recording a customer's payment; an
        // order's status and fulfilment are never written from outside.
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
