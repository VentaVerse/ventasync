<?php

use App\Mcp\VentaSyncServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', VentaSyncServer::class)
    ->middleware(['throttle:api', 'mcp.auth', 'mcp.access'])
    ->name('mcp');

Route::middleware('mcp.sign-in')->group(fn () => Mcp::oauthRoutes());
