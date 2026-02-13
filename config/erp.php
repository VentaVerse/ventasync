<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ERP Settings
    |--------------------------------------------------------------------------
    |
    | This project is intended to be a private ERP system.
    | User self-registration should be disabled by default.
    |
    */
    'allow_registration' => (bool) env('ERP_ALLOW_REGISTRATION', false),
];
