<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Catalog Configuration
    |--------------------------------------------------------------------------
    |
    | Table prefix for catalog (OpenCart-based) tables.
    | Controlled via .env using DB_TABLE_PREFIX
    |
    */

    'prefix' => env('DB_TABLE_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Default Language ID
    |--------------------------------------------------------------------------
    */

    'default_language_id' => 1,

    /*
    |--------------------------------------------------------------------------
    | Public Catalog URL (OpenCart storefront)
    |--------------------------------------------------------------------------
    |
    | Lazada image migration requires public, reachable image URLs.
    | If your ERP runs on a different subdomain (e.g. laravel.example.com)
    | while your storefront images are served from example.com, set:
    |
    |   CATALOG_PUBLIC_URL=https://example.com
    |
    */

    'public_url' => env('CATALOG_PUBLIC_URL', env('APP_URL', '')),

    /*
    |--------------------------------------------------------------------------
    | Public Image Directory Prefix
    |--------------------------------------------------------------------------
    |
    | OpenCart usually serves images under /image/....
    | Keep this configurable for custom setups.
    |
    */

    'image_prefix' => trim((string) env('CATALOG_IMAGE_PREFIX', 'image'), '/'),

];
