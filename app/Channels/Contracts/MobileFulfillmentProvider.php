<?php

namespace App\Channels\Contracts;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface MobileFulfillmentProvider
{
    public function mobileFulfillmentActions(): array;

    public function mobileFulfillmentAction(string $action, int $id, Request $request): Response;
}
