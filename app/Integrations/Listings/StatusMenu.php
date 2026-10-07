<?php

namespace App\Integrations\Listings;

final class StatusMenu
{
    public const TREE = ['all' => 'All', 'not_uploaded' => 'Not live on %s', 'uploaded' => 'Live on %s'];

    public const VALUES = ['all', 'not_uploaded', 'uploaded'];

    public const EVERYTHING = 'All products';

    public const UNGROUPED = 'Ungrouped';

    public const RETIRED = [
        'ready' => 'not_uploaded',
        'not_ready' => 'not_uploaded',
        'listed_ready' => 'uploaded',
        'listed_not_ready' => 'uploaded',
    ];

    public static function stand(?string $candidate): string
    {
        $value = is_string($candidate) ? trim($candidate) : 'all';
        $value = self::RETIRED[$value] ?? $value;

        return in_array($value, self::VALUES, true) ? $value : 'all';
    }

    public static function group(?string $candidate): string
    {
        $value = is_string($candidate) ? trim($candidate) : '';
        if ($value === 'none') {
            return 'none';
        }

        return ctype_digit($value) && (int) $value > 0 ? $value : 'all';
    }

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
        if (isset($base['sync_status']) && isset(self::RETIRED[(string) $base['sync_status']])) {
            $base['sync_status'] = self::RETIRED[(string) $base['sync_status']];
        }
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
            'newGroup' => ($spec['newGroup'] ?? null) ?: null,
            'label' => $parts === [] ? null : implode(' · ', $parts),
        ];
    }

    public static function groupChoices(array $menu): array
    {
        $out = [];
        foreach ((array) ($menu['groups'] ?? []) as $group) {
            if (ctype_digit((string) $group['key'])) {
                $out[(string) $group['key']] = (string) $group['label'];
            }
        }
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    public static function chosenGroup(array $menu): ?string
    {
        foreach ((array) ($menu['groups'] ?? []) as $group) {
            if (! empty($group['active']) && ctype_digit((string) $group['key'])) {
                return (string) $group['key'];
            }
        }

        return null;
    }
}
