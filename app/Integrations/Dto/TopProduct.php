<?php

namespace App\Integrations\Dto;

class TopProduct
{
    public function __construct(
        public string $sku,
        public string $name,
        public ?string $imageUrl,
        public int $qtySold,
        public float $revenue,
        /** The variation this SKU is, as the channel wrote it ("Red", "Color: Red"), or null. */
        public ?string $variation = null,
    ) {
    }
}
