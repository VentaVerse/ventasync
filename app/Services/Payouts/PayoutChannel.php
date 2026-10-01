<?php

namespace App\Services\Payouts;

use Closure;

/**
 * One marketplace that pays out per order (the owner, 2026-10-01): Shopee,
 * Lazada and TikTok Shop each register one from their own provider, so the
 * Payouts report, the dashboard and the store pages list exactly the
 * channels that are installed, and nothing in core names a marketplace.
 */
final class PayoutChannel
{
    /**
     * @param  array<string, mixed>  $attention  the PayoutAttention descriptor of its order table
     * @param  Closure(): iterable<object>  $stores  its stores that are switched on
     * @param  Closure(object): array  $readBalance  the live money at the marketplace for one store
     * @param  string  $pageRoute  the store's own Payouts page, taking {store}
     * @param  (Closure(object): list<array<string, mixed>>)|null  $extraFacts  figures from the ERP's own data
     */
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

    /** @return list<object> */
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

    /** @return list<array<string, mixed>> */
    public function extraFacts(object $store): array
    {
        return $this->extraFacts ? ($this->extraFacts)($store) : [];
    }

    /** Whether this person may see what this channel's stores are paid. */
    public function visibleTo(?object $user): bool
    {
        return $user !== null && method_exists($user, 'hasPermission') && $user->hasPermission('view_' . $this->id . '/payout');
    }
}
