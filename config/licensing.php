<?php

return [

    'server_url' => env('LICENSE_SERVER_URL', 'https://ventasync.com'),

    'pricing_url' => env('VENTASYNC_PRICING_URL', 'https://ventasync.com/pricing'),

    'core_slug' => env('LICENSE_CORE_SLUG', 'ventasync'),

    'cache_ttl' => env('LICENSE_CACHE_TTL', 60 * 60 * 24),

    'http_timeout' => env('LICENSE_HTTP_TIMEOUT', 5),

    'trial_window_days' => env('LICENSE_TRIAL_WINDOW_DAYS', 30),

];
