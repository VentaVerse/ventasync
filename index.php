<?php

/*
|--------------------------------------------------------------------------
| Bootstrap shim for shared hosting
|--------------------------------------------------------------------------
|
| Forwards the request to Laravel's real entry point at public/index.php.
| This lets VentaSync install on hosting panels whose document root
| is the project root (cPanel, HestiaCP, CyberPanel, Plesk) without
| requiring the customer to reconfigure DocumentRoot to public/.
|
| Companion .htaccess at the project root blocks direct access to
| application code paths (app/, config/, .env, etc.) so the security
| boundary that public/ normally provides is preserved.
*/

require __DIR__ . '/public/index.php';
