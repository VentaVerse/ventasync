<?php

namespace App\Integrations\Contracts;

/**
 * WHAT A CHANNEL CHARGED FOR ONE ORDER, IN THE FOUR BUCKETS EVERY REPORT
 * READS.
 *
 * The reports used to reach into lazada_orders, shopee_orders and
 * tiktok_orders themselves and read each one's private JSON keys - a file
 * about profit knew the string `$.order_income.seller_transaction_fee`.
 * That coupling is why adding a channel meant editing the reports, why
 * Venta and WooCommerce had no fees in them at all, and why separating the
 * delivery charge out of fees had to be done twice in two places and was
 * missed once (the owner, 2026-09-10: "If the former, then its a coupling").
 *
 * So the direction is inverted. A channel knows its own payload and maps
 * it to these four buckets; core normalizes them onto the order and the
 * reports read core alone. Adding a channel is now zero report changes.
 *
 * The mapping is a PURE READ of what is already stored, never a call to a
 * marketplace: the live sync and the backfill both go through it, so an
 * order normalized today and the same order normalized in a month agree.
 */
interface MarketplaceFeeSource
{
    /**
     * The channel's fees for this catalog order, or null when the order is
     * not this channel's - or is, and the channel has nothing recorded for
     * it yet. Null means "I cannot answer", never "zero".
     *
     * Amounts are POSITIVE magnitudes; core writes them as the deductions
     * they are.
     *
     * @return array{commission: float, payment: float, shipping: float, other: float}|null
     */
    public function feeBucketsForOrder(int $coreOrderId): ?array;
}
