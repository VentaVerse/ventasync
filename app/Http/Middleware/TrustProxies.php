<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * Set to '*' so Laravel honors X-Forwarded-* headers from any upstream
     * proxy (Cloudflare, Traefik, nginx, etc.). Without this,
     * request()->isSecure() returns false on HTTPS-behind-proxy, and
     * asset()/@vite() generate http:// URLs that browsers block as
     * mixed content. Affects any self-hosted install fronted by a CDN
     * or reverse proxy.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = '*';

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
