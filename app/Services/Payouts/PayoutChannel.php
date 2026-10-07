<?php

namespace App\Services\Payouts;

use Closure;

final class PayoutChannel
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $accent,
        public readonly array $attention,
        private readonly Closure $stores,
        private readonly Closure $readBalance,
        public readonly string $pageRoute,
        private readonly ?Closure $extraFacts = null,
    ) {
    }

    public function stores(): array
    {
        $out = [];
        foreach (($this->stores)() as $store) {
            $out[] = $store;
        }

        return $out;
    }

    public function readBalance(object $store): array
    {
        return ($this->readBalance)($store);
    }

    public function extraFacts(object $store): array
    {
        return $this->extraFacts ? ($this->extraFacts)($store) : [];
    }

    public function visibleTo(?object $user): bool
    {
        return $user !== null && method_exists($user, 'hasPermission') && $user->hasPermission('view_' . $this->id . '/payout');
    }
}
