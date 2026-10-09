<?php

namespace App\Channels\Contracts;

interface StoreOptionsProvider
{
    public function availableStoreOptions(): array;
}
