<?php

namespace App\Channels\Contracts;

use Illuminate\Http\Request;

interface MobileMarketplaceProvider
{
    public function mobileChannelSlug(): string;

    public function mobileIndexResponse(Request $request): array;

    public function mobileShowResponse(int $id): ?array;
}
