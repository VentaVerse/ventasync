<?php

namespace App\Channels\Contracts;

use Illuminate\Http\Request;

interface RootRouteHandler
{
    public function handleRootRoute(Request $request): mixed;
}
