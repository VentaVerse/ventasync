<?php

return [

    'default_language_id' => 1,

    'public_url' => env('CATALOG_PUBLIC_URL', env('APP_URL', '')),

    'image_prefix' => trim((string) env('CATALOG_IMAGE_PREFIX', 'storage'), '/'),

];
