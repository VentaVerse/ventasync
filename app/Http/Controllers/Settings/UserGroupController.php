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
    private function getGroupedPermissions(array $selected = []): array
    {
        $catalog = app(PermissionCatalogue::class);
        $rows = Permission::whereIn('key', $catalog->keys())->get()->keyBy('key');
        $held = array_flip($selected);

        $groups = [];

        foreach ($catalog->grouped() as $group) {
            $out = [];

            foreach ($group['rows'] as $row) {
                $view = $rows->get('view_' . $row['key']);
                $manage = $rows->get('manage_' . $row['key']);

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

        $originalName = strtolower((string) ($original['name'] ?? ''));

        $group->name = $request->name;
        $group->save();

        $permissionIds = $request->permissions ?? [];

        // The Administrator group must keep the keys that reach this page, or everyone is locked out.
        if ($originalName === 'administrator') {
            $coreKeys = ['manage_settings/user_group', 'manage_settings/user', 'manage_settings/setting'];
            $coreIds = Permission::whereIn('key', $coreKeys)->pluck('id')->all();
            $permissionIds = array_values(array_unique(array_merge($permissionIds, $coreIds)));
        }

        $before = $group->permissions()->pluck('key', 'permissions.id');

        $disabled = \App\Models\Extension::where('enabled', false)->pluck('id')->all();
        $unseen = $before->filter(function ($key) use ($disabled) {
            $area = preg_match('/^(?:view|manage)_([a-z0-9_]+)\//', (string) $key, $m) ? $m[1] : '';

            return $area !== '' && in_array($area, $disabled, true);
        })->keys()->all();
        $permissionIds = array_values(array_unique(array_merge(array_map('intval', $permissionIds), $unseen)));

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

        if (strtolower($group->name) === 'administrator') {
            return redirect()->route('user_groups.index')
                ->with('error', 'Administrator user group cannot be deleted');
        }

        $assigned = User::where('user_group_id', (int) $group->id)->count();

        if ($assigned > 0) {
            return redirect()->route('user_groups.index')
                ->with('error', 'Cannot delete this group because there are '.$assigned.' user(s) assigned to it');
        }

        $groupName = $group->name;

        DB::transaction(function () use ($group) {
            $group->permissions()->detach();
            $group->delete();
        });

        ActivityLogger::log('deleted', 'User Group', (int) $id, $groupName);

        return redirect()->route('user_groups.index');
    }

}
