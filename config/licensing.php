<?php

return [

    /*
    |--------------------------------------------------------------------------
    | License server base URL
    |--------------------------------------------------------------------------
    |
    | Override via LICENSE_SERVER_URL in .env. Production default matches the
    | deployed VentaSync license authority.
    |
    */
    'server_url' => env('LICENSE_SERVER_URL', 'https://example.com'),

    /*
    |--------------------------------------------------------------------------
    | Core product slug
    |--------------------------------------------------------------------------
    |
    | The slug registered on the license server for the ERP core itself. The
    | activation flow validates the typed key matches this slug before storing.
    |
    */
    'core_slug' => env('LICENSE_CORE_SLUG', 'ventasync-legacy'),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | How long to cache a successful validation response before re-calling the
    | server. Unreachable server during cache refresh does NOT invalidate the
    | license — only explicit server 403 with revoked/expired does.
    |
    */
    'cache_ttl' => env('LICENSE_CACHE_TTL', 60 * 60 * 24), // 24h

    /*
    |--------------------------------------------------------------------------
    | HTTP client timeout (seconds)
    |--------------------------------------------------------------------------
    */
    'http_timeout' => env('LICENSE_HTTP_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Background re-validation threshold (seconds)
    |--------------------------------------------------------------------------
    |
    | If last_validated_at is older than this, the next request triggers a
    | background re-check. Server-unreachable is tolerated; cache simply
    | refreshes on the next attempt.
    |
    */
    'revalidate_after' => env('LICENSE_REVALIDATE_AFTER', 60 * 60 * 24 * 30), // 30d

    /*
    |--------------------------------------------------------------------------
    | Trial users — data window (days)
    |--------------------------------------------------------------------------
    |
    | Trial/unlicensed installs see only the last N days of high-volume data
    | (orders, stock movements) via LicenseAwareScope. Full licenses see all.
    |
    */
    'trial_window_days' => env('LICENSE_TRIAL_WINDOW_DAYS', 30),

];
