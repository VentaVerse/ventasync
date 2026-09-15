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
    public const TREE = ['all' => 'All', 'not_uploaded' => 'Not listed', 'uploaded' => 'Listed'];

    public const NOT_LISTED_PARTS = ['ready' => 'Ready to push', 'not_ready' => 'Needs details'];

    public const LISTED_PARTS = ['listed_ready' => 'Ready', 'listed_not_ready' => 'Needs details'];

    /** Every sync_status value the tree answers to. */
    public const VALUES = ['all', 'not_uploaded', 'ready', 'not_ready', 'uploaded', 'listed_ready', 'listed_not_ready'];

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

        $entry = fn (string $key, string $label, bool $active, string $tone = '', bool $parent = false, array $kids = []) => [
            'key' => $key, 'label' => $label, 'count' => (int) ($counts[$key] ?? 0), 'tone' => $tone,
            'url' => $to(['sync_status' => $key === 'all' ? null : $key]), 'active' => $active, 'parent' => $parent, 'kids' => $kids, 'meta' => null,
        ];

        $kids = function (array $parts) use ($entry, $sync) {
            $out = [];
            foreach ($parts as $key => $label) {
                $out[] = $entry($key, $label, $sync === $key, str_ends_with($key, 'not_ready') ? '' : 'good');
            }

            return $out;
        };

        $tree = [
            $entry('all', self::TREE['all'], $sync === 'all'),
            $entry('not_uploaded', self::TREE['not_uploaded'], $sync === 'not_uploaded', '', isset(self::NOT_LISTED_PARTS[$sync]), $kids(self::NOT_LISTED_PARTS)),
            $entry('uploaded', self::TREE['uploaded'], $sync === 'uploaded', '', isset(self::LISTED_PARTS[$sync]), $kids(self::LISTED_PARTS)),
        ];

        $flags = [
            ['key' => 'failed', 'label' => 'Push failed', 'count' => (int) ($counts['failed'] ?? 0), 'tone' => 'bad', 'on' => $failed,
                'url' => $to(['failed' => $failed ? null : 1, 'sync_status' => $sync === 'all' ? null : $sync])],
            ['key' => 'change', 'label' => 'Catalog change', 'count' => (int) ($counts['change'] ?? 0), 'tone' => 'attn', 'on' => $change,
                'url' => $to(['change' => $change ? null : 1])],
        ];

        $parts = [];
        if (isset(self::NOT_LISTED_PARTS[$sync])) {
            $parts[] = self::TREE['not_uploaded'] . ', ' . self::NOT_LISTED_PARTS[$sync];
        } elseif (isset(self::LISTED_PARTS[$sync])) {
            $parts[] = self::TREE['uploaded'] . ', ' . self::LISTED_PARTS[$sync];
        } elseif ($sync !== 'all') {
            $parts[] = self::TREE[$sync] ?? $sync;
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
