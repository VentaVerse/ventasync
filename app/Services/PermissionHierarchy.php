<?php

namespace App\Services;

use App\Extensions\ExtensionManager;
use Illuminate\Support\Facades\Cache;

/**
 * Builds the unified permission tree by merging the core permission
 * config (config/permissions.php) with permissions declared in each
 * installed extension's manifest.
 *
 * Two consumer surfaces:
 *
 *  1. User::hasPermission() — needs parent⇄children lookups so that a
 *     user with a parent permission inherits all children, and vice versa
 *     a user with any child counts as having the parent (for sidebar
 *     visibility checks).
 *
 *  2. UserGroupController — needs the grouped tree (top-level groups
 *     with their direct children, plus subgroups inside container
 *     groups like Marketplace API) for the user-group edit form.
 *
 * The tree is cached for 24 hours and invalidated by ExtensionManager
 * whenever an extension is enabled, disabled, installed, or uninstalled.
 *
 * Manifest schema (extension.json "permissions"):
 *   - Legacy: ["manage_shopee", "manage_shopee_orders"]
 *     → permissions registered with no parent, no subgroup
 *   - New:    [{"key": "...", "parent": "...", "subgroup": "..."}, ...]
 *     → parent enables hasPermission expansion; subgroup label groups
 *       sibling permissions visually under the parent in the UI tree.
 */
class PermissionHierarchy
{
    private const CACHE_KEY = 'permission_hierarchy_v1';
    private const CACHE_TTL_SECONDS = 86400; // 24 hours

    /**
     * Flat parent → [children] map for hasPermission() expansion.
     *
     * @return array<string, string[]>
     */
    public function parentToChildren(): array
    {
        return $this->build()['parent_to_children'];
    }

    /**
     * Reverse map: child → parent. null if the permission has no parent.
     *
     * @return array<string, ?string>
     */
    public function childToParent(): array
    {
        return $this->build()['child_to_parent'];
    }

    /**
     * Get the parent key for a permission, or null.
     */
    public function parentOf(string $key): ?string
    {
        return $this->childToParent()[$key] ?? null;
    }

    /**
     * Get the immediate children of a permission. Empty array if none.
     *
     * @return string[]
     */
    public function childrenOf(string $key): array
    {
        return $this->parentToChildren()[$key] ?? [];
    }

    /**
     * Grouped tree for the User Groups UI. One entry per top-level group
     * (catalog, sales, marketplace_api, purchasing, warehousing, settings,
     * etc). Each entry includes either flat children OR named subgroups
     * (for container groups whose children come from multiple extensions).
     *
     * @return array<int, array{key:string,label:string,parent_key:string,children:string[]|null,subgroups:array|null,sort:int}>
     */
    public function groupedTree(): array
    {
        return $this->build()['tree'];
    }

    /**
     * Label for a single permission key, falling back to the key itself.
     */
    public function labelFor(string $key): string
    {
        return $this->build()['labels'][$key] ?? $key;
    }

    /**
     * Drop the cached hierarchy. Called by ExtensionManager after any
     * extension state change.
     */
    public function invalidate(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Build (or read from cache) the full hierarchy.
     */
    private function build(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            return $this->compute();
        });
    }

    /**
     * Walk core config + every extension manifest and assemble:
     *   - parent_to_children: flat parent → [child keys]
     *   - child_to_parent:    flat child → parent key
     *   - labels:             flat key → human label
     *   - tree:               nested top-level → subgroups/children for UI
     */
    private function compute(): array
    {
        $coreConfig = config('permissions', []);

        $parentToChildren = [];
        $childToParent = [];
        $labels = [];
        $sortOrder = [];
        // subgroup membership: parent_key => subgroup_label => [permission_keys]
        $subgroupMembers = [];
        // Permission keys that go directly under a parent (no subgroup level)
        $directChildren = [];

        // 1. Core permissions
        foreach ($coreConfig as $key => $meta) {
            $parent = $meta['parent'] ?? null;
            $labels[$key] = $meta['label'] ?? $key;
            $sortOrder[$key] = $meta['sort'] ?? 100;
            $childToParent[$key] = $parent;
            if ($parent !== null) {
                $parentToChildren[$parent][] = $key;
                $directChildren[$parent][] = $key;
            }
        }

        // 2. Extension-contributed permissions.
        // Read every installed manifest (not just enabled extensions) so the
        // User Groups UI can still show + assign permissions that belong to a
        // temporarily-disabled extension. Children whose permission rows have
        // been removed are naturally filtered out by the consumer
        // (UserGroupController::getGroupedPermissions resolves keys against
        // the live permissions table).
        $manager = app(ExtensionManager::class);
        foreach ($manager->getManifests() as $manifest) {
            $perms = $manifest['permissions'] ?? [];
            foreach ($perms as $perm) {
                $entry = $this->normalizePermissionEntry($perm);
                if ($entry === null) {
                    continue;
                }
                $key = $entry['key'];
                $parent = $entry['parent'];
                $subgroup = $entry['subgroup'];

                $labels[$key] = $entry['label'] ?? $labels[$key] ?? $key;
                $childToParent[$key] = $parent;
                if ($entry['sort'] !== null) {
                    $sortOrder[$key] = $entry['sort'];
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

        // Dedup each list
        foreach ($parentToChildren as $p => $kids) {
            $parentToChildren[$p] = array_values(array_unique($kids));
        }
        foreach ($directChildren as $p => $kids) {
            $directChildren[$p] = array_values(array_unique($kids));
        }

        // 3. Build the top-level grouped tree
        $tree = [];
        foreach ($childToParent as $key => $parent) {
            if ($parent !== null) {
                continue; // not a top-level group
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
                // If the same parent has BOTH subgroups and direct children,
                // surface the direct ones as their own "Other" subgroup so
                // they don't get lost.
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
            'labels'             => $labels,
            'tree'               => $tree,
        ];
    }

    /**
     * Accept either string (legacy: "manage_shopee") or object form
     * ({"key": "...", "parent": "...", "subgroup": "...", "label": "..."}).
     *
     * @return ?array{key:string,parent:?string,subgroup:?string,label:?string}
     */
    private function normalizePermissionEntry(mixed $perm): ?array
    {
        if (is_string($perm)) {
            return ['key' => $perm, 'parent' => null, 'subgroup' => null, 'label' => null, 'sort' => null];
        }
        if (is_array($perm) && !empty($perm['key']) && is_string($perm['key'])) {
            return [
                'key'      => $perm['key'],
                'parent'   => isset($perm['parent']) && is_string($perm['parent']) ? $perm['parent'] : null,
                'subgroup' => isset($perm['subgroup']) && is_string($perm['subgroup']) ? $perm['subgroup'] : null,
                'label'    => isset($perm['label']) && is_string($perm['label']) ? $perm['label'] : null,
                'sort'     => isset($perm['sort']) && is_numeric($perm['sort']) ? (int) $perm['sort'] : null,
            ];
        }
        return null;
    }

    private function slugify(string $key): string
    {
        return str_replace('manage_', '', $key);
    }
}
