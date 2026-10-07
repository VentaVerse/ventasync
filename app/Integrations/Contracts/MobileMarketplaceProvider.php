<?php

namespace App\Integrations\Contracts;

use Illuminate\Http\Request;

interface MobileMarketplaceProvider
{
    public function mobilePlatformSlug(): string;

    public function mobileIndexResponse(Request $request): array;

    public function mobileShowResponse(int $id): ?array;
}
