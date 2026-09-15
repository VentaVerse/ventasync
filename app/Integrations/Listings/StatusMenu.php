<?php

namespace App\Integrations\Listings;

/**
 * The status menu down the left of a store's Listings page, shaped once for
 * every channel (partials/listing-status-menu).
 *
 * Where it stands is one tree: All; Not listed, split into Ready to push and
 * Needs details; Listed, with the store's own statuses under it. Needs you
 * is two switches, Push failed and Catalog change, that narrow whatever the
 * tree has chosen. The tree keeps each channel's existing sync_status values
 * and store-status parameter, so every old link still lands where it did; an
 * old sync_status=error link reads as Push failed switched on.
 */
final class StatusMenu
{
    public const TREE = ['all' => 'All', 'not_uploaded' => 'Not listed', 'uploaded' => 'Listed'];

    public const NOT_LISTED_PARTS = ['ready' => 'Ready to push', 'not_ready' => 'Needs details'];

    /**
     * @param  array{
     *     url: callable(array<string, mixed>): string,
     *     query: array<string, mixed>,
     *     sync: string,
     *     failed: bool,
     *     change: bool,
     *     counts: array<string, int>,
     *     market?: array{param: string, current: string, defs: array<string, string>, counts: ?array<string, int>, meta: string, store: string}
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
        $market = $spec['market'] ?? null;
        $marketParam = $market['param'] ?? null;
        $marketOn = $market && $market['counts'] !== null && ($market['current'] ?? 'all') !== 'all' ? $market['current'] : null;
        $counts = $spec['counts'];

        $base = $query;
        if (($base['sync_status'] ?? null) === 'error') {
            unset($base['sync_status']);
            $base['failed'] = 1;
        }

        $to = function (array $set, array $drop = []) use ($base, $spec) {
            $params = $base;
            foreach ($drop as $key) {
                unset($params[$key]);
            }
            foreach ($set as $key => $value) {
                if ($value === null) {
                    unset($params[$key]);
                } else {
                    $params[$key] = $value;
                }
            }

            return ($spec['url'])($params);
        };
        $dropMarket = $marketParam ? [$marketParam] : [];

        $entry = fn (string $key, string $label, string $url, bool $active, string $tone = '', bool $parent = false, array $kids = [], ?string $meta = null) => [
            'key' => $key, 'label' => $label, 'count' => (int) ($counts[$key] ?? 0), 'tone' => $tone,
            'url' => $url, 'active' => $active, 'parent' => $parent, 'kids' => $kids, 'meta' => $meta,
        ];

        $notListedKids = [];
        foreach (self::NOT_LISTED_PARTS as $key => $label) {
            $notListedKids[] = $entry($key, $label, $to(['sync_status' => $key], $dropMarket), $sync === $key, $key === 'ready' ? 'good' : '');
        }

        $listedKids = [];
        if ($market && $market['counts'] !== null) {
            foreach ($market['defs'] as $key => $label) {
                $listedKids[] = [
                    'key' => $key, 'label' => $label, 'count' => (int) ($market['counts'][$key] ?? 0), 'tone' => '',
                    'url' => $to(['sync_status' => 'uploaded', $marketParam => $key]),
                    'active' => $marketOn === $key, 'parent' => false, 'kids' => [], 'meta' => null,
                ];
            }
        }

        $tree = [
            $entry('all', self::TREE['all'], $to(['sync_status' => null], $dropMarket), $sync === 'all' && $marketOn === null),
            $entry('not_uploaded', self::TREE['not_uploaded'], $to(['sync_status' => 'not_uploaded'], $dropMarket),
                $sync === 'not_uploaded', '', isset(self::NOT_LISTED_PARTS[$sync]), $notListedKids),
            $entry('uploaded', self::TREE['uploaded'], $to(['sync_status' => 'uploaded'], $dropMarket),
                $sync === 'uploaded' && $marketOn === null, '', $marketOn !== null, $listedKids,
                $listedKids !== [] ? 'On ' . $market['store'] . ', ' . $market['meta'] : null),
        ];

        $flags = [
            ['key' => 'failed', 'label' => 'Push failed', 'count' => (int) ($counts['failed'] ?? 0), 'tone' => 'bad', 'on' => $failed,
                'url' => $to(['failed' => $failed ? null : 1, 'sync_status' => $sync === 'all' ? null : $sync])],
            ['key' => 'change', 'label' => 'Catalog change', 'count' => (int) ($counts['change'] ?? 0), 'tone' => 'attn', 'on' => $change,
                'url' => $to(['change' => $change ? null : 1])],
        ];

        $parts = [];
        if ($sync !== 'all' || $marketOn !== null) {
            if (isset(self::NOT_LISTED_PARTS[$sync])) {
                $parts[] = self::TREE['not_uploaded'] . ', ' . self::NOT_LISTED_PARTS[$sync];
            } elseif ($marketOn !== null) {
                $parts[] = self::TREE['uploaded'] . ', ' . ($market['defs'][$marketOn] ?? $marketOn);
            } else {
                $parts[] = self::TREE[$sync] ?? $sync;
            }
        }
        if ($failed) {
            $parts[] = 'Push failed';
        }
        if ($change) {
            $parts[] = 'Catalog change';
        }

        return ['tree' => $tree, 'flags' => $flags, 'label' => $parts === [] ? null : implode(' · ', $parts)];
    }
}
