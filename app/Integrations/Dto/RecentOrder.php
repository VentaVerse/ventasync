<?php

namespace App\Integrations\Dto;

use DateTimeInterface;

class RecentOrder
{
    public function __construct(
        public string $reference,
        public ?string $customerName,
        public float $total,
        public string $statusLabel,
        public ?DateTimeInterface $orderedAt,
        public ?string $url = null,
    ) {
    }
}
