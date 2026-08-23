<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Models\Admin\Permission;
use App\Services\ActivityLogger;
use App\Services\PermissionCatalogue;
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
    /**
     * The permission areas, derived from the routes, ready to render.
     *
     * The tree this replaced was built from config/permissions.php and a
     * hand-kept parent/child hierarchy, which is why it needed twin
     * collapsing, orphan carry-over and a parent-surfacing pass to show
     * everything the database held - and still missed thirteen keys.
     *
     * There is no tree now. An area is a folder of controllers and a row is
     * one controller, which is what OpenCart's model gives us: the shape
     * comes from the routes, so the view has nothing to reconcile.
     *
     * @return array<int, array{area:string,label:string,rows:array<int,array>}>
     */
    private function getGroupedPermissions(array $selected = []): array
    {
        $catalogue = app(PermissionCatalogue::class);
        $rows = Permission::whereIn('key', $catalogue->keys())->get()->keyBy('key');
        $held = array_flip($selected);

        $groups = [];

        foreach ($catalogue->grouped() as $group) {
            $out = [];

            foreach ($group['rows'] as $row) {
                $view = $rows->get('view_' . $row['key']);
                $manage = $rows->get('manage_' . $row['key']);

                /* A row nothing in the database backs cannot be granted, so
                 * it is not offered. That happens when the migration has not
                 * run yet, or an extension registered after it did; the sync
                 * command is what closes it, and showing a control that
                 * cannot save would be worse than showing nothing. */
                if (! $view && ! $manage) {
                    continue;
                }

                $state = 'off';

                if ($manage && isset($held[$manage->id])) {
                    $state = 'manage';
                } elseif ($view && isset($held[$view->id])) {
                    $state = 'view';
                }

                $out[] = [
                    'key' => $row['key'],
                    'label' => $row['label'],
                    'routes' => $row['routes'],
                    'writes' => $row['writes'],
                    'view' => $view,
                    'manage' => $manage,
                    // Two segments when there is no write route to manage.
                    'segments' => $manage ? 3 : 2,
                    'state' => $state,
                    'warning' => $row['warning'],
                ];
            }

            if ($out !== []) {
                $groups[] = [
                    'area' => $group['area'],
                    'label' => $group['label'],
                    'machine' => $group['machine'],
                    'note' => $group['note'],
                    'rows' => $out,
                ];
            }
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
        $selected = [];

        return view('settings.user_groups.create', compact('permissionGroups', 'selected'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:user_groups,name',
            'permissions' => 'array',
            'permissions.*' => 'integer',
        ]);

        $group = UserGroup::create(['name' => $request->name]);

        // Every row is an independent grant - the parent/child tree is
        // retired, so exactly what was posted is exactly what lands.
        $group->permissions()->sync($request->permissions ?? []);

        ActivityLogger::log('created', 'User Group', $group->id, $group->name);

        return redirect()->route('user_groups.index');
    }

    public function edit($id)
    {
        $group = UserGroup::findOrFail((int)$id);
        $selected = $group->permissions()->pluck('permissions.id')->all();
        $permissionGroups = $this->getGroupedPermissions($selected);

        return view('settings.user_groups.edit', compact('group', 'permissionGroups', 'selected'));
    }

    /**
     * A copy of the group, permissions and all, ready to edit.
     *
     * Building a role from scratch means sixty-odd decisions; building it
     * from the nearest existing role means changing the handful that
     * differ. The copy is created immediately and lands on its edit page -
     * a "draft" that vanished with a closed tab would teach people not to
     * trust the button. The name says what it is until somebody renames it.
     */
    public function duplicate(Request $request, $id)
    {
        $source = UserGroup::findOrFail((int) $id);

        $copy = DB::transaction(function () use ($source) {
            $name = $source->name . ' (copy)';
            $n = 2;

            while (UserGroup::where('name', $name)->exists()) {
                $name = $source->name . ' (copy ' . $n++ . ')';
            }

            $copy = UserGroup::create(['name' => $name]);
            $copy->permissions()->attach($source->permissions()->pluck('permissions.id')->all());

            return $copy;
        });

        ActivityLogger::log('duplicated', 'User Group', $copy->id, $copy->name, [
            'source' => $source->name,
            'source_id' => $source->id,
            'permissions_copied' => $copy->permissions()->count(),
        ]);

        return redirect()
            ->route('user_groups.edit', $copy->id)
            ->with('status', "'{$copy->name}' created with {$source->name}'s permissions. Adjust and rename it here.");
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

        /* Safeguard: the Administrator group always keeps the keys that
         * reach THIS page and its neighbours - stripping them (an over-eager
         * "Off all", a bad import) would lock everyone out of the screen
         * that hands access back. Derived keys, like everything else. */
        if ($originalName === 'administrator') {
            $coreKeys = ['manage_settings/user_group', 'manage_settings/user', 'manage_settings/setting'];
            $coreIds = Permission::whereIn('key', $coreKeys)->pluck('id')->all();
            $permissionIds = array_values(array_unique(array_merge($permissionIds, $coreIds)));
        }

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

}
