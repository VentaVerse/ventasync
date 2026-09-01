<?php

/*
|--------------------------------------------------------------------------
| Diagnostic log housekeeping
|--------------------------------------------------------------------------
|
| Only what CORE owns is listed here. api_request_logs is the audit trail of
| calls made INTO this ERP by other software (the mobile app, an assistant
| over MCP), so it is core's to keep and core's to trim.
|
| Sync logs and per-marketplace API logs belong to the extensions that write
| them and are declared in each extension.json under "logs" (see
| App\Support\LogRetention). Core does not name an extension's tables:
| syncing is not a core feature, and a core settings page that trimmed
| venta_sync_logs would be core reaching into a module (the owner, 2026-09-01).
|
| These are housekeeping defaults, not business settings, which is why they
| live in config rather than in the settings table. A deployment that wants
| different numbers sets them here or by env.
|
*/

return [

    // Diagnostic tables core itself owns: table => days to keep.
    'core' => [
        'api_request_logs' => (int) env('API_REQUEST_LOG_KEEP_DAYS', 30),
    ],

    // Used when an extension declares a log table with no keep_days of its own.
    'default_keep_days' => (int) env('LOG_KEEP_DAYS', 30),

    // What a sync run is worth recording, for a store that has not chosen.
    // Every channel that keeps a sync log offers its own control; this is
    // only the fallback. all | failures | off
    'sync_level' => env('SYNC_LOG_LEVEL', 'all'),

];
