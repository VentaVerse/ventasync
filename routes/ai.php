<?php

use App\Mcp\VentaSyncServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP (#131)
|--------------------------------------------------------------------------
|
| One endpoint, served by the ERP itself over MCP's Streamable HTTP
| transport: no daemon, nothing for HestiaCP to keep alive. The caller is
| an API application (Settings, API) with MCP switched on; its Sanctum
| token and scopes decide which tools it is shown and may run. Every tool
| call is audited to api_request_logs as mcp:<tool>.
|
*/

Mcp::web('/mcp', VentaSyncServer::class)
    ->middleware(['throttle:api', 'auth:sanctum', 'mcp.access'])
    ->name('mcp');
