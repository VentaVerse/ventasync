<?php

return [

    'core' => [
        'api_request_logs' => (int) env('API_REQUEST_LOG_KEEP_DAYS', 30),
    ],

    'default_keep_days' => (int) env('LOG_KEEP_DAYS', 30),

    'sync_level' => env('SYNC_LOG_LEVEL', 'all'),

];
