<?php

namespace App\Integrations\Contracts;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface MobileFulfilmentProvider
{
    public function mobileFulfilmentActions(): array;

    public function mobileFulfilmentAction(string $action, int $id, Request $request): Response;
}
