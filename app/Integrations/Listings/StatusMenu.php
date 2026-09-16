<?php

namespace App\Integrations\Listings;

/**
 * The status menu down the left of a store's Listings page, shaped once for
 * every channel (partials/listing-status-menu). It shows VentaSync's own
 * view of each product; the store's own statuses are the tab row above the
 * table, and the two combine.
 *
 * Where it stands is one tree: All; Not listed, split into Ready to push and
 * Needs details; Listed, split into Ready and Needs details. Needs you is two
 * switches, Push failed and Catalog change, that narrow whatever the tree
 * has chosen. The tree keeps each channel's sync_status parameter, so every
 * old link still lands where it did; an old sync_status=error link reads as
 * Push failed switched on.
 */
final class StatusMenu
{
    /**
     * The store's name rides the two halves, because "Live" is a fact about
     * a marketplace and not about us: a product is live ON somewhere.
     */
    public const TREE = ['all' => 'All', 'not_uploaded' => 'Not live on %s', 'uploaded' => 'Live on %s'];

    /**
     * ELIGIBLE, not Ready.
     *
     * Ready promises an action still to come, which is true before a product
     * goes up and false the moment it is selling. The check underneath never
     * changed: does this listing hold everything the store demands. Before it
     * is live that decides whether it may go; after it is live it decides
     * whether an update would be accepted. Eligible carries both.
     */
    public const NOT_LISTED_PARTS = ['ready' => 'Eligible', 'not_ready' => 'Needs details'];

    public const LISTED_PARTS = ['listed_ready' => 'Eligible', 'listed_not_ready' => 'Needs details'];

    /** Every sync_status value the tree answers to. */
    public const VALUES = ['all', 'not_uploaded', 'ready', 'not_ready', 'uploaded', 'listed_ready', 'listed_not_ready'];

    /**
     * WHY a product cannot go, in the words a column of counts can carry.
     *
     * The codes are ListingState's; the labels here are short on purpose.
     * A row's amber line spells the whole sentence ("a package weight and
     * size (LxWxH) on the catalog product") because it is teaching one
     * product's reader what to do. A menu line is a heading over a count,
     * read down a column beside eleven others, and the sentence is what
     * made "Needs details" unreadable in the first place.
     *
     * One list for all five channels: a channel that cannot have a cause
     * simply never counts it, and a channel that gains one gains the line
     * in the same place on every store.
     *
     * The order is the fallback when two causes hold the same number; the
     * menu itself leads with whichever holds the most products, because
     * that is the one worth an operator's afternoon.
     */
    public const CAUSES = [
        ListingState::GAP_DISABLED => 'Disabled in the catalog',
        ListingState::GAP_PARCEL => 'Package weight and size',
        ListingState::GAP_SKU => 'SKU',
        ListingState::GAP_CATEGORY => 'Category',
        ListingState::GAP_ATTRIBUTES => 'Category attributes',
        ListingState::GAP_SHEET => 'Category attribute sheet',
        ListingState::GAP_COURIERS => 'Couriers',
        ListingState::GAP_IMAGE => 'Product image',
        ListingState::GAP_NAME => 'Name',
        ListingState::GAP_DESCRIPTION => 'Description',
        ListingState::GAP_PRICE => 'Price',
        ListingState::GAP_VARIATIONS => 'Variations',
    ];

    /** The gap code a `gap=` address may carry, or 'all' when it names none we know. */
    public static function cause(?string $candidate): string
    {
        return is_string($candidate) && array_key_exists($candidate, self::CAUSES) ? $candidate : 'all';
    }

    /**
     * @param  array{
     *     url: callable(array<string, mixed>): string,
     *     query: array<string, mixed>,
     *     sync: string,
     *     failed: bool,
     *     change: bool,
     *     counts: array<string, int>
     * }  $spec
     * @return array{tree: list<array<string, mixed>>, flags: list<array<string, mixed>>, label: ?string}
     */
    public static function build(array $spec): array
    {
        $query = $spec['query'];
        unset($query['page']);
        $sync = $spec['sync'] === 'error' ? 'all' : $spec['sync'];
        $failed = $spec['failed'] || $spec['sync'] === 'error';
        $change = $spec['change'];
        $counts = $spec['counts'];

        $base = $query;
        if (($base['sync_status'] ?? null) === 'error') {
            unset($base['sync_status']);
            $base['failed'] = 1;
        }

        $to = function (array $set) use ($base, $spec) {
            $params = $base;
            foreach ($set as $key => $value) {
                if ($value === null) {
                    unset($params[$key]);
                } else {
                    $params[$key] = $value;
                }
            }

            return ($spec['url'])($params);
        };

        // Every tree node clears `gap`: a cause is a sub-choice of one Needs
        // details node, so moving anywhere else drops it. Without this the
        // cause stayed in the URL, kept its chip highlighted, and kept
        // narrowing the rows under a heading that no longer showed it - which
        // is how "Catalog change: 7" could open to an empty table.
        $entry = fn (string $key, string $label, bool $active, string $tone = '', bool $parent = false, array $kids = []) => [
            'key' => $key, 'label' => $label, 'count' => (int) ($counts[$key] ?? 0), 'tone' => $tone,
            'url' => $to(['sync_status' => $key === 'all' ? null : $key, 'gap' => null]), 'active' => $active, 'parent' => $parent, 'kids' => $kids, 'meta' => null,
        ];

        /* WHY a product is not eligible, hanging off the Needs details it
         * explains. It was a block of its own for one afternoon and read as
         * the same idea twice: Needs details IS what is missing, so the
         * causes belong under it, counted within that half. */
        $order = array_flip(array_keys(self::CAUSES));
        $gap = self::cause($spec['gap'] ?? null);
        $causesFor = function (string $key) use ($spec, $order, $gap, $to): array {
            $out = [];
            foreach ((array) ($spec['causes'][$key] ?? []) as $code => $count) {
                $label = self::CAUSES[$code] ?? null;
                if ($label === null || (int) $count < 1) {
                    continue;
                }
                $on = $gap === $code;
                $out[] = [
                    'key' => $code, 'label' => $label, 'count' => (int) $count, 'tone' => 'attn', 'on' => $on,
                    'url' => $to(['sync_status' => $key, 'gap' => $on ? null : $code]),
                ];
            }
            usort($out, fn ($a, $b) => [$b['count'], $order[$a['key']]] <=> [$a['count'], $order[$b['key']]]);

            return $out;
        };

        $kids = function (array $parts) use ($entry, $sync, $causesFor) {
            $out = [];
            foreach ($parts as $key => $label) {
                $kid = $entry($key, $label, $sync === $key, str_ends_with($key, 'not_ready') ? '' : 'good');
                $kid['causes'] = str_ends_with($key, 'not_ready') ? $causesFor($key) : [];
                $out[] = $kid;
            }

            return $out;
        };

        $store = trim((string) ($spec['store'] ?? '')) ?: 'the store';
        $half = fn (string $key) => sprintf(self::TREE[$key], $store);

        $tree = [
            $entry('all', self::TREE['all'], $sync === 'all'),
            $entry('not_uploaded', $half('not_uploaded'), $sync === 'not_uploaded', '', isset(self::NOT_LISTED_PARTS[$sync]), $kids(self::NOT_LISTED_PARTS)),
            $entry('uploaded', $half('uploaded'), $sync === 'uploaded', '', isset(self::LISTED_PARTS[$sync]), $kids(self::LISTED_PARTS)),
        ];

        // The flags clear `gap` too: their counts are taken store-wide (not
        // within a cause), so a flag must open to that same set, not to the
        // intersection with a cause left over in the URL.
        $flags = [
            ['key' => 'failed', 'label' => 'Push failed', 'count' => (int) ($counts['failed'] ?? 0), 'tone' => 'bad', 'on' => $failed,
                'url' => $to(['failed' => $failed ? null : 1, 'sync_status' => $sync === 'all' ? null : $sync, 'gap' => null])],
            ['key' => 'change', 'label' => 'Catalog change', 'count' => (int) ($counts['change'] ?? 0), 'tone' => 'attn', 'on' => $change,
                'url' => $to(['change' => $change ? null : 1, 'gap' => null])],
        ];

        $parts = [];
        if (isset(self::NOT_LISTED_PARTS[$sync])) {
            $parts[] = $half('not_uploaded') . ', ' . self::NOT_LISTED_PARTS[$sync];
        } elseif (isset(self::LISTED_PARTS[$sync])) {
            $parts[] = $half('uploaded') . ', ' . self::LISTED_PARTS[$sync];
        } elseif ($sync !== 'all') {
            $parts[] = isset(self::TREE[$sync]) ? $half($sync) : $sync;
        }
        if ($failed) {
            $parts[] = 'Push failed';
        }
        if ($change) {
            $parts[] = 'Catalog change';
        }

        if ($gap !== 'all') {
            $parts[] = self::CAUSES[$gap];
        }

        return ['tree' => $tree, 'flags' => $flags, 'label' => $parts === [] ? null : implode(' · ', $parts)];
    }
}
