<?php

namespace App\Integrations\Listings;

/**
 * The menu down the left of a store's Listings page, shaped once for every
 * channel (partials/listing-status-menu). The rail is the store's product
 * groups: a product belongs to at most one, so the counts add up to the
 * store and a number never overlaps its neighbour.
 *
 * Where a product stands with the marketplace is the segment above the table
 * (partials/listing-stand-segment). It narrows whichever group is chosen,
 * so the two never compete for the same place on the page and cannot
 * disagree: one filter in the rail, one above the table.
 *
 * Filters are two switches, Push failed and Catalog change, that narrow
 * whichever group is chosen rather than replacing it. An error is not a
 * place in the catalogue, it is something waiting for the seller.
 */
final class StatusMenu
{
    /**
     * The store's name rides the two halves, because "Live" is a fact about
     * a marketplace and not about us: a product is live ON somewhere.
     */
    public const TREE = ['all' => 'All', 'not_uploaded' => 'Not live on %s', 'uploaded' => 'Live on %s'];

    /** Every sync_status value the segment answers to. */
    public const VALUES = ['all', 'not_uploaded', 'uploaded'];

    /** The rail's own first entry: the whole store, whatever it is grouped into. */
    public const EVERYTHING = 'All products';

    /** The products no group claims. Never a footnote: on a real store it is one of the larger entries. */
    public const UNGROUPED = 'Not in a group';

    /**
     * The eligibility rungs the menu used to carry, and the half each one
     * belonged to.
     *
     * They are gone. The page says where a product stands and which group it
     * is in; a product short of something says so on its own row and is
     * refused at push, so counting the shortfalls in the menu bought a
     * whole-catalogue pass for a number that read zero on a healthy store.
     *
     * An address that still names a rung lands on its half rather than
     * nowhere, so a bookmark, a shared link or a dashboard chip keeps working.
     */
    public const RETIRED = [
        'ready' => 'not_uploaded',
        'not_ready' => 'not_uploaded',
        'listed_ready' => 'uploaded',
        'listed_not_ready' => 'uploaded',
    ];

    /** Where a `sync_status=` address stands, with a retired rung folded into its half. */
    public static function stand(?string $candidate): string
    {
        $value = is_string($candidate) ? trim($candidate) : 'all';
        $value = self::RETIRED[$value] ?? $value;

        return in_array($value, self::VALUES, true) ? $value : 'all';
    }

    /** The group a `group=` address names: an id, `none` for the ungrouped, else all. */
    public static function group(?string $candidate): string
    {
        $value = is_string($candidate) ? trim($candidate) : '';
        if ($value === 'none') {
            return 'none';
        }

        return ctype_digit($value) && (int) $value > 0 ? $value : 'all';
    }

    /**
     * @param  array{
     *     url: callable(array<string, mixed>): string,
     *     query: array<string, mixed>,
     *     sync: string,
     *     group?: ?string,
     *     failed: bool,
     *     change: bool,
     *     counts: array<string, int>,
     *     groups?: list<array{id: int|string, name: string, count: int}>,
     *     newGroup?: ?string,
     *     store?: ?string
     * }  $spec
     * @return array{everything: array<string, mixed>, groups: list<array<string, mixed>>, stand: list<array<string, mixed>>, flags: list<array<string, mixed>>, newGroup: ?string, label: ?string}
     */
    public static function build(array $spec): array
    {
        $query = $spec['query'];
        unset($query['page']);
        $stand = self::stand($spec['sync'] ?? 'all');
        $failed = ($spec['failed'] ?? false) || ($spec['sync'] ?? '') === 'error';
        $change = (bool) ($spec['change'] ?? false);
        $counts = $spec['counts'] ?? [];
        $chosen = self::group($spec['group'] ?? null);

        $base = $query;
        if (($base['sync_status'] ?? null) === 'error') {
            unset($base['sync_status']);
            $base['failed'] = 1;
        }
        /* A retired rung in the address is rewritten before any link is built
           from it, so every link off this page carries the half, never the
           rung that no longer exists. */
        if (isset($base['sync_status']) && isset(self::RETIRED[(string) $base['sync_status']])) {
            $base['sync_status'] = self::RETIRED[(string) $base['sync_status']];
        }
        // The causes are gone; an old address stops carrying one here.
        unset($base['gap']);

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

        /* The left menu. Biggest first, because the eye goes to the group
           with the most work in it; a tie reads alphabetically so the order
           is stable from one load to the next. A group with nothing in it is
           still drawn: a group you made and never filled is worth seeing.
           The products no group claims sit at the bottom whatever their
           number, because they are the leftovers of the list above, not a
           group competing with it for size. */
        $entries = [];
        $ungrouped = null;
        foreach ((array) ($spec['groups'] ?? []) as $group) {
            $key = trim((string) ($group['id'] ?? ''));
            $name = trim((string) ($group['name'] ?? ''));
            if ($key === '' || $name === '') {
                continue;
            }
            $entry = ['key' => $key, 'label' => $name, 'count' => (int) ($group['count'] ?? 0)];
            if ($key === 'none') {
                $ungrouped = $entry;

                continue;
            }
            $entries[] = $entry;
        }
        usort($entries, fn ($a, $b) => [$b['count'], $a['label']] <=> [$a['count'], $b['label']]);
        if ($ungrouped !== null) {
            $entries[] = $ungrouped;
        }

        /* The whole store, above the heading and apart from the list, because
           it is not a group: it is what you see when no group is chosen.
           Its number is counted under the chosen half and NOT under any
           group, so it differs from the segment's All, which is counted
           inside the chosen group. Two questions, two counts; sharing one
           key made each of them wrong in turn. */
        $everything = [
            'key' => 'all',
            'label' => self::EVERYTHING,
            'count' => (int) ($counts['store'] ?? $counts['all'] ?? 0),
            'active' => $chosen === 'all',
            'url' => $to(['group' => null, 'sync_status' => null]),
        ];

        $groups = [];
        $name = null;
        foreach ($entries as $entry) {
            $on = $chosen === $entry['key'];
            if ($on) {
                $name = $entry['label'];
            }
            $groups[] = [
                'key' => $entry['key'],
                'label' => $entry['label'],
                'count' => $entry['count'],
                'active' => $on,
                'url' => $to(['group' => $entry['key'], 'sync_status' => null]),
            ];
        }

        /* The segment above the table: the same three places the tree used to
           hold, narrowing whatever group the rail has chosen. */
        $store = trim((string) ($spec['store'] ?? '')) ?: 'the store';
        $standing = [];
        foreach (self::TREE as $key => $label) {
            $standing[] = [
                'key' => $key,
                'label' => $key === 'all' ? $label : sprintf($label, $store),
                'count' => (int) ($counts[$key] ?? 0),
                'on' => $stand === $key,
                'url' => $to(['sync_status' => $key === 'all' ? null : $key]),
            ];
        }

        /* A filter narrows whatever the menu has already chosen: its link
           carries the group and the half along with it. So its count is
           taken inside that same choice, never across the whole store, or
           the number would promise one list and open a smaller one. */
        $flags = [
            ['key' => 'failed', 'label' => 'Push failed', 'count' => (int) ($counts['failed'] ?? 0), 'tone' => 'bad', 'on' => $failed,
                'url' => $to(['failed' => $failed ? null : 1, 'sync_status' => $stand === 'all' ? null : $stand])],
            ['key' => 'change', 'label' => 'Catalog change', 'count' => (int) ($counts['change'] ?? 0), 'tone' => 'attn', 'on' => $change,
                'url' => $to(['change' => $change ? null : 1])],
        ];

        $parts = [];
        if ($chosen === 'none') {
            $parts[] = self::UNGROUPED;
        } elseif ($name !== null) {
            $parts[] = $name;
        }
        if ($stand !== 'all') {
            $parts[] = sprintf(self::TREE[$stand], $store);
        }
        if ($failed) {
            $parts[] = 'Push failed';
        }
        if ($change) {
            $parts[] = 'Catalog change';
        }

        return [
            'everything' => $everything,
            'groups' => $groups,
            'stand' => $standing,
            'flags' => $flags,
            /* Making a group is the one thing you do TO the list itself, so
               it sits on the list's own heading rather than among the
               choices underneath it. Where it returns to is the page you
               left, which the form captures for itself. */
            'newGroup' => ($spec['newGroup'] ?? null) ?: null,
            'label' => $parts === [] ? null : implode(' · ', $parts),
        ];
    }
}
