<?php

namespace App\Channels\Contracts;

interface ListingLists
{
    public function names(): array;

    public function refresh(string $list): array;
}
