<?php

namespace App\Services;

use App\Extensions\ExtensionManager;
use Illuminate\Support\Facades\Cache;

class PermissionHierarchy
{
    private const CACHE_KEY = 'permission_hierarchy_v2';
    private const CACHE_TTL_SECONDS = 86400;

    public function parentToChildren(): array
    {
        return $this->build()['parent_to_children'];
    }

    public function childToParent(): array
    {
        return $this->build()['child_to_parent'];
    }

    public function parentOf(string $key): ?string
    {
        return $this->childToParent()[$key] ?? null;
    }

    public function childrenOf(string $key): array
    {
        return $this->parentToChildren()[$key] ?? [];
    }

    public function ownerOf(string $key): ?string
    {
        return $this->build()['owners'][$key] ?? null;
    }

    public function declaredTiersOf(string $key): array
    {
        return $this->build()['declared_tiers'][$key] ?? [];
    }

    public function tiersOf(string $key): array
    {
        $base = $this->baseOf($key);
        if ($base === null) {
            return [];
        }
        $keys = $this->build()['child_to_parent'];
        $tiers = [];
        if (array_key_exists('view_' . $base, $keys)) {
            $tiers[] = 'view';
        }
        if (array_key_exists('manage_' . $base, $keys)) {
            $tiers[] = 'manage';
        }
        return $tiers;
    }

    public function baseOf(string $key): ?string
    {
        if (str_starts_with($key, 'manage_')) {
            return substr($key, 7);
        }
        if (str_starts_with($key, 'view_')) {
            return substr($key, 5);
        }
        return null;
    }

    public function groupedTree(): array
    {
        return $this->build()['tree'];
    }

    public function invalidate(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function build(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            return $this->compute();
        });
    }

    private function compute(): array
    {
        $coreConfig = config('permissions', []);

        $parentToChildren = [];
        $childToParent = [];
        $labels = [];
        $owners = [];
        $sortOrder = [];
        $declaredTiers = [];
        $subgroupMembers = [];
        $directChildren = [];

        foreach ($coreConfig as $key => $meta) {
            $parent = $meta['parent'] ?? null;
            $labels[$key] = $meta['label'] ?? $key;
            $sortOrder[$key] = $meta['sort'] ?? 100;
            $childToParent[$key] = $parent;
            $owners[$key] = null;
            if ($parent !== null) {
                $parentToChildren[$parent][] = $key;
                $directChildren[$parent][] = $key;
            }
            if (isset($meta['tiers']) && is_array($meta['tiers'])) {
                $coreTiers = [];
                foreach ($meta['tiers'] as $t) {
                    if (is_string($t) && in_array($t, ['view', 'manage'], true)) {
                        $coreTiers[] = $t;
                    }
                }
                if (!empty($coreTiers)) {
                    $declaredTiers[$key] = array_values(array_unique($coreTiers));
                }
            }
        }

        $manager = app(ExtensionManager::class);
        foreach ($manager->getManifests() as $extensionId => $manifest) {
            $perms = $manifest['permissions'] ?? [];
            foreach ($perms as $perm) {
                $entry = $this->normalizePermissionEntry($perm);
                if ($entry === null) {
                    continue;
                }
                $key = $entry['key'];
                $parent = $entry['parent'];
                $subgroup = $entry['subgroup'];

                $labels[$key] = $labels[$key] ?? $key;
                $childToParent[$key] = $parent;
                $owners[$key] = $extensionId;
                if ($entry['sort'] !== null) {
                    $sortOrder[$key] = $entry['sort'];
                }
                if (!empty($entry['tiers'])) {
                    $declaredTiers[$key] = $entry['tiers'];
                }
                if ($parent !== null) {
                    $parentToChildren[$parent][] = $key;
                    if ($subgroup !== null) {
                        $subgroupMembers[$parent][$subgroup][] = $key;
                    } else {
                        $directChildren[$parent][] = $key;
                    }
                }
            }
        }

        foreach ($parentToChildren as $p => $kids) {
            $parentToChildren[$p] = array_values(array_unique($kids));
        }
        foreach ($directChildren as $p => $kids) {
            $directChildren[$p] = array_values(array_unique($kids));
        }

        $tree = [];
        foreach ($childToParent as $key => $parent) {
            if ($parent !== null) {
                continue;
            }
            $entry = [
                'key'         => $this->slugify($key),
                'label'       => $labels[$key] ?? $key,
                'parent_key'  => $key,
                'sort'        => $sortOrder[$key] ?? 100,
                'children'    => null,
                'subgroups'   => null,
            ];
            $subs = $subgroupMembers[$key] ?? [];
            $direct = $directChildren[$key] ?? [];

            if (!empty($subs)) {
                ksort($subs);
                $entry['subgroups'] = [];
                foreach ($subs as $label => $kids) {
                    $entry['subgroups'][] = [
                        'label'    => $label,
                        'children' => array_values(array_unique($kids)),
                    ];
                }
                if (!empty($direct)) {
                    array_unshift($entry['subgroups'], [
                        'label'    => 'General',
                        'children' => $direct,
                    ]);
                }
            } else {
                $entry['children'] = $direct;
            }

            $tree[] = $entry;
        }

        usort($tree, fn ($a, $b) => $a['sort'] <=> $b['sort']);

        return [
            'parent_to_children' => $parentToChildren,
            'child_to_parent'    => $childToParent,
            'owners'             => $owners,
            'declared_tiers'     => $declaredTiers,
            'tree'               => $tree,
        ];
    }

    private function normalizePermissionEntry(mixed $perm): ?array
    {
        if (is_string($perm)) {
            return ['key' => $perm, 'parent' => null, 'subgroup' => null, 'sort' => null, 'tiers' => []];
        }
        if (is_array($perm) && !empty($perm['key']) && is_string($perm['key'])) {
            $tiers = [];
            if (isset($perm['tiers']) && is_array($perm['tiers'])) {
                foreach ($perm['tiers'] as $t) {
                    if (is_string($t) && in_array($t, ['view', 'manage'], true)) {
                        $tiers[] = $t;
                    }
                }
                $tiers = array_values(array_unique($tiers));
            }
            return [
                'key'      => $perm['key'],
                'parent'   => isset($perm['parent']) && is_string($perm['parent']) ? $perm['parent'] : null,
                'subgroup' => isset($perm['subgroup']) && is_string($perm['subgroup']) ? $perm['subgroup'] : null,
                'sort'     => isset($perm['sort']) && is_numeric($perm['sort']) ? (int) $perm['sort'] : null,
                'tiers'    => $tiers,
            ];
        }
        return null;
    }

    private function slugify(string $key): string
    {
        return str_replace('manage_', '', $key);
    }
}
