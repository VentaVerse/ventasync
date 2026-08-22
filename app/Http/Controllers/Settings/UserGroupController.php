<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Models\Admin\Permission;
use App\Services\ActivityLogger;
use App\Services\PermissionHierarchy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserGroupController extends Controller
{
    /**
     * Build grouped permissions for the form. Reads from the dynamic
     * PermissionHierarchy service so the tree picks up extensions
     * automatically when they're installed — core has no hardcoded
     * marketplace / purchasing / warehousing knowledge here.
     */
    private function getGroupedPermissions(): array
    {
        $all = Permission::orderBy('key')->get()->keyBy('key');
        $tree = app(PermissionHierarchy::class)->groupedTree();

        $resolve = function (array $keys) use ($all): array {
            $out = [];
            foreach ($keys as $k) {
                if ($all->has($k)) {
                    $out[] = $all->get($k);
                }
            }
            return $out;
        };

        $groups = [];
        foreach ($tree as $entry) {
            $parent = $all->get($entry['parent_key']);
            if (!$parent) {
                // Parent permission doesn't exist in DB (e.g. extension
                // disabled and permissions removed). Skip the whole group.
                continue;
            }
            // Group label = the parent permission's resolved label, which the
            // Permission model accessor walks through the
            // ext-{owner}::permissions → core lang → humanized chain. This
            // means an extension-owned top-level group (Purchasing,
            // Warehousing, Petty Cash) picks up its label from the
            // extension's own lang file without core knowing the extension.
            $group = [
                'key'    => $entry['key'],
                'label'  => $parent->label,
                'parent' => $parent,
            ];
            if (!empty($entry['subgroups'])) {
                $subgroups = [];
                foreach ($entry['subgroups'] as $sg) {
                    $kids = $resolve($sg['children']);
                    if (!empty($kids)) {
                        $subgroups[] = ['label' => $sg['label'], 'children' => $kids];
                    }
                }
                if (empty($subgroups)) {
                    continue; // container with no surviving children
                }
                $group['subgroups'] = $subgroups;
            } else {
                $group['children'] = $resolve($entry['children'] ?? []);
            }
            $groups[] = $group;
        }

        return $groups;
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->get('q',''));
        $groups = UserGroup::query()
            ->when($q !== '', fn($query) => $query->where('name','like','%'.$q.'%'))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('settings.user_groups.index', compact('groups','q'));
    }

    public function create()
    {
        $permissionGroups = $this->getGroupedPermissions();
        return view('settings.user_groups.create', compact('permissionGroups'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:user_groups,name',
            'permissions' => 'array',
            'permissions.*' => 'integer',
        ]);

        $group = UserGroup::create(['name' => $request->name]);

        // Child grants auto-elevate their parent to view tier (same rule as update()).
        $permissionIds = $this->applyChildParentElevation($request->permissions ?? []);
        $group->permissions()->sync($permissionIds);

        ActivityLogger::log('created', 'User Group', $group->id, $group->name);

        return redirect()->route('user_groups.index');
    }

    public function edit($id)
    {
        $group = UserGroup::findOrFail((int)$id);
        $permissionGroups = $this->getGroupedPermissions();
        $selected = $group->permissions()->pluck('permissions.id')->all();

        return view('settings.user_groups.edit', compact('group', 'permissionGroups', 'selected'));
    }

    public function update(Request $request, $id)
    {
        $group = UserGroup::findOrFail((int)$id);

        $request->validate([
            'name' => 'required|string|max:255|unique:user_groups,name,'.$group->id,
            'permissions' => 'array',
            'permissions.*' => 'integer',
        ]);

        $original = $group->getAttributes();

        /* THE NAME BEFORE THE EDIT, captured here because the safeguard below
         * depends on it. It used to run after $group->name was reassigned, so
         * it tested the SUBMITTED name: renaming Administrator to anything
         * else and pressing "Off all" in the same save stripped the group that
         * holds manage_user_groups, and nobody could reach this page again.
         * A guard that a single field edit switches off is not a guard. */
        $originalName = strtolower((string) ($original['name'] ?? ''));

        $group->name = $request->name;
        $group->save();

        $permissionIds = $request->permissions ?? [];

        // Safeguard: Administrator group must always keep core permissions
        if ($originalName === 'administrator') {
            $coreKeys = ['manage_settings', 'manage_user_groups', 'manage_users'];
            $coreIds = Permission::whereIn('key', $coreKeys)->pluck('id')->all();
            $permissionIds = array_values(array_unique(array_merge($permissionIds, $coreIds)));
        }

        $permissionIds = $this->applyChildParentElevation($permissionIds);

        /* WHAT ACCESS ACTUALLY MOVED, which is the only reason this page
         * exists and was the one thing the ledger did not record.
         *
         * diff() was called with ['name'], so renaming a group was logged and
         * handing somebody every permission in the application was not. This
         * is the screen that decides who can delete a live marketplace
         * listing; a change here has to be answerable on Monday morning.
         *
         * Keys, not ids: an id means nothing to whoever reads the ledger, and
         * a permission row can be deleted and reissued with a different one.
         * Read before the sync, because after it there is nothing to compare. */
        $before = $group->permissions()->pluck('key', 'permissions.id');

        $group->permissions()->sync($permissionIds);

        $after = $group->fresh()->permissions()->pluck('key', 'permissions.id');

        $granted = $after->diffKeys($before)->values()->sort()->values()->all();
        $revoked = $before->diffKeys($after)->values()->sort()->values()->all();

        $changes = ActivityLogger::diff($original, $group->getAttributes(), ['name']);

        if ($granted !== []) {
            $changes['permissions_granted'] = $granted;
        }

        if ($revoked !== []) {
            $changes['permissions_revoked'] = $revoked;
        }

        ActivityLogger::log('updated', 'User Group', $group->id, $group->name, $changes);

        /* SAY WHAT MOVED, on the way out.
         *
         * Save redirected to a list of ID and Name with no flash and no diff,
         * so the last thing the operator saw after changing who can delete a
         * live marketplace listing was the same uninformative table they
         * started from, with no evidence anything had happened.
         *
         * The counts, not the keys: the ledger carries the keys for whoever
         * audits it later, and a person who has just pressed Save wants to
         * know the shape of what they did. Silent when nothing changed,
         * because "0 granted, 0 revoked" is noise. */
        if ($granted !== [] || $revoked !== []) {
            $parts = [];

            if ($granted !== []) {
                $parts[] = count($granted) . ' granted';
            }

            if ($revoked !== []) {
                $parts[] = count($revoked) . ' revoked';
            }

            return redirect()->route('user_groups.index')
                ->with('status', $group->name . ': ' . implode(', ', $parts) . '.');
        }

        return redirect()->route('user_groups.index');
    }

    public function destroy($id)
    {
        $group = UserGroup::findOrFail((int)$id);

        // do not allow deleting Administrator group
        if (strtolower($group->name) === 'administrator') {
            return redirect()->route('user_groups.index')
                ->with('error', 'Administrator user group cannot be deleted');
        }

        /* ASK BEFORE DESTROYING. detach() used to run first and the count
         * second, so refusing the delete left the group standing with every
         * permission stripped: reproduced on Fulfilment, 14 permissions to 0,
         * while the operator read "Cannot delete this group because there are
         * 1 user(s) assigned to it" and reasonably concluded nothing had
         * happened. The person in that group lost every menu and found out
         * hours later. */
        $assigned = User::where('user_group_id', (int) $group->id)->count();

        if ($assigned > 0) {
            return redirect()->route('user_groups.index')
                ->with('error', 'Cannot delete this group because there are '.$assigned.' user(s) assigned to it');
        }

        $groupName = $group->name;

        /* And both writes together, so a failure between them cannot leave a
         * group with no permissions instead of no group. */
        DB::transaction(function () use ($group) {
            $group->permissions()->detach();
            $group->delete();
        });

        ActivityLogger::log('deleted', 'User Group', (int) $id, $groupName);

        return redirect()->route('user_groups.index');
    }

    /**
     * Ensure a parent permission is present whenever any of its children
     * are granted. Maintains the invariant that the parent's tier is
     * always >= max(children's tiers), so a child grant never lands as
     * a dead permission (parent Off hides the sidebar section, making
     * the child unreachable from nav).
     *
     * Mirrors the client-side cascade in permission-pill.js: granting
     * a child auto-elevates the parent to view tier — or to manage for
     * container parents that don't expose a view tier.
     *
     * Why this is permissive (elevates parent) rather than restrictive
     * (drops orphan children): the child grant is the explicit user
     * action, so preserving it best matches operator intent. The UI
     * already prevents orphan-child states; this method is the
     * defensive backstop for DOM tamper / direct-API submission.
     *
     * Parent-Off-cascades-children is handled UI-side only — by the
     * time the form posts, children have already been flipped Off, so
     * the server just persists what was submitted.
     *
     * @param  array<int, int>  $permissionIds  raw ids from the form
     * @return array<int, int>                 ids with elevated parents added
     */
    private function applyChildParentElevation(array $permissionIds): array
    {
        if (empty($permissionIds)) {
            return $permissionIds;
        }

        $hierarchy = app(\App\Services\PermissionHierarchy::class);
        $rows = Permission::whereIn('id', $permissionIds)->pluck('key', 'id')->all();
        $grantedKeys = array_flip($rows);

        $parentKeysToAdd = [];
        foreach ($rows as $key) {
            $parent = $hierarchy->parentOf($key);
            if ($parent === null) {
                continue; // top-level grant — no parent to elevate
            }
            $base = $hierarchy->baseOf($parent);
            if ($base !== null) {
                // Twin parent (view_X + manage_X). Granted when either tier present.
                if (!isset($grantedKeys['view_' . $base]) && !isset($grantedKeys['manage_' . $base])) {
                    $parentKeysToAdd[] = 'view_' . $base;
                }
            } elseif (!isset($grantedKeys[$parent])) {
                // Container parent (only one key, no view/manage split).
                $parentKeysToAdd[] = $parent;
            }
        }

        if (empty($parentKeysToAdd)) {
            return $permissionIds;
        }

        // Resolve to ids. view_X may not exist for a manage-only parent —
        // fall back to manage_X in that case.
        $parentKeysToAdd = array_unique($parentKeysToAdd);
        $existing = Permission::whereIn('key', $parentKeysToAdd)->pluck('id', 'key')->all();
        $fallbackKeys = [];
        foreach ($parentKeysToAdd as $key) {
            if (!isset($existing[$key]) && str_starts_with($key, 'view_')) {
                $fallbackKeys[] = 'manage_' . substr($key, 5);
            }
        }
        if (!empty($fallbackKeys)) {
            $existing = array_merge(
                $existing,
                Permission::whereIn('key', $fallbackKeys)->pluck('id', 'key')->all()
            );
        }

        return array_values(array_unique(array_merge($permissionIds, array_values($existing))));
    }
}
