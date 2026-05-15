<?php

namespace App\Http\Controllers;

use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Models\Admin\Permission;
use App\Services\ActivityLogger;
use App\Services\PermissionHierarchy;
use Illuminate\Http\Request;

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

        return view('user_groups.index', compact('groups','q'));
    }

    public function create()
    {
        $permissionGroups = $this->getGroupedPermissions();
        return view('user_groups.create', compact('permissionGroups'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:user_groups,name',
            'permissions' => 'array',
            'permissions.*' => 'integer',
        ]);

        $group = UserGroup::create(['name' => $request->name]);
        $group->permissions()->sync($request->permissions ?? []);

        ActivityLogger::log('created', 'User Group', $group->id, $group->name);

        return redirect()->route('user_groups.index');
    }

    public function edit($id)
    {
        $group = UserGroup::findOrFail((int)$id);
        $permissionGroups = $this->getGroupedPermissions();
        $selected = $group->permissions()->pluck('permissions.id')->all();

        return view('user_groups.edit', compact('group', 'permissionGroups', 'selected'));
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
        $group->name = $request->name;
        $group->save();

        $permissionIds = $request->permissions ?? [];

        // Safeguard: Administrator group must always keep core permissions
        if (strtolower($group->name) === 'administrator') {
            $coreKeys = ['manage_settings', 'manage_user_groups', 'manage_users'];
            $coreIds = Permission::whereIn('key', $coreKeys)->pluck('id')->all();
            $permissionIds = array_values(array_unique(array_merge($permissionIds, $coreIds)));
        }

        $group->permissions()->sync($permissionIds);

        $changes = ActivityLogger::diff($original, $group->getAttributes(), ['name']);
        ActivityLogger::log('updated', 'User Group', $group->id, $group->name, $changes);

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

        $group->permissions()->detach();
                $assigned = User::where('user_group_id', (int) $group->id)->count();
        if ($assigned > 0) {
            return redirect()->route('user_groups.index')
                ->with('error', 'Cannot delete this group because there are '.$assigned.' user(s) assigned to it');
        }

        $groupName = $group->name;
        $group->delete();

        ActivityLogger::log('deleted', 'User Group', (int) $id, $groupName);

        return redirect()->route('user_groups.index');
    }
}
