<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Marketplace push deliveries carry no session and sign their own
        // way (Phase E, #184); the controller verifies per channel.
        'webhooks/*',
    ];
}
