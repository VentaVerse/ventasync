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

];
