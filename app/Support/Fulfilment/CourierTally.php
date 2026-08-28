<?php

namespace App\Support\Fulfilment;

/**
 * The parcels waiting at To Handover, split by the courier that will
 * collect them, and the filter that split drives.
 *
 * A packing bench does not hand over in one go. Riders arrive one courier
 * at a time and in no fixed order, so when the J&T rider is at the door the
 * question is "which of these are J&T's", and within that answer the queue
 * is still first in, first out. Each channel already walked its handover
 * orders to count them per courier; this turns that walk into something the
 * list can filter by, so the count on the strip and the rows under it are
 * the same set of orders by construction.
 *
 * Each channel keeps its own way of reading the courier out of the raw
 * marketplace payload. What is shared is everything after that: how a raw
 * carrier string becomes a name a packer recognises, how names become
 * stable keys a URL can carry, and what the strip receives.
 */
final class CourierTally
{
    public const UNKNOWN = 'Unknown';

    /**
     * @var array<string, array{label: string, count: int, ids: array<int>}>
     */
    private array $couriers = [];

    private int $total = 0;

    /**
     * Record one parcel under the courier that will carry it.
     *
     * The raw string is whatever the marketplace sent; an empty one files
     * the parcel under Unknown rather than dropping it, because a parcel
     * with no courier on record still has to leave the building.
     */
    public function add(int $orderId, ?string $rawCourier): void
    {
        $label = self::displayName($rawCourier);
        $key = self::key($label);

        if (! isset($this->couriers[$key])) {
            $this->couriers[$key] = ['label' => $label, 'count' => 0, 'ids' => []];
        }

        $this->couriers[$key]['count']++;
        $this->couriers[$key]['ids'][] = $orderId;
        $this->total++;
    }

    /**
     * The order ids under one courier key, or null when the key names no
     * courier in this tally. Null, not an empty list, so a caller can tell
     * a stale link (the last of that courier's parcels already left) from a
     * courier that is present with nothing under it, which cannot happen
     * here but would be a silent empty page if it were confused with the
     * first.
     *
     * @return array<int>|null
     */
    public function idsFor(string $key): ?array
    {
        return $this->couriers[$key]['ids'] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->couriers[$key]);
    }

    /**
     * What the strip renders: busiest courier first, ties in the order they
     * were first seen, so two couriers with the same count do not swap
     * places between one page load and the next.
     *
     * @return array{total: int, active: string, items: array<string, array{label: string, count: int}>}
     */
    public function forStrip(string $activeKey = ''): array
    {
        $items = $this->couriers;
        uasort($items, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'total' => $this->total,
            'active' => $this->has($activeKey) ? $activeKey : '',
            'items' => array_map(
                fn ($c) => ['label' => $c['label'], 'count' => $c['count']],
                $items
            ),
        ];
    }

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    /**
     * The name a packer reads off the parcel, from whatever the
     * marketplace sent.
     *
     * Lazada writes "Pickup: J&T Express PH, Delivery: J&T Express PH": two
     * legs, both the same carrier, and a country suffix that says nothing
     * on a shop that only ships in one. The rider at the door is the
     * delivery leg's, so that is the leg kept. Shopee and TikTok send the
     * carrier's name on its own and pass through untouched except for the
     * same suffix.
     */
    public static function displayName(?string $raw): string
    {
        $name = trim((string) $raw);

        if ($name === '') {
            return self::UNKNOWN;
        }

        if (preg_match('/delivery:\s*([^,]+)/i', $name, $m)) {
            $name = trim($m[1]);
        } elseif (preg_match('/pickup:\s*([^,]+)/i', $name, $m)) {
            $name = trim($m[1]);
        }

        $name = preg_replace('/\s+PH$/i', '', $name) ?? $name;

        return $name !== '' ? $name : self::UNKNOWN;
    }

    /**
     * The key a URL carries for a courier. Lowercase and trimmed, nothing
     * cleverer: a slug would need a reverse map to become a name again, and
     * the query string encodes spaces and ampersands on its own.
     */
    public static function key(string $displayName): string
    {
        return mb_strtolower(trim($displayName));
    }
}
