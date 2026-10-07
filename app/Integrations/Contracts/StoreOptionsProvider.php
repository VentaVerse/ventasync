<?php

namespace App\Integrations\Contracts;

interface StoreOptionsProvider
{
    public function availableStoreOptions(): array;
}
