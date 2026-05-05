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
    ) {
    }
}
