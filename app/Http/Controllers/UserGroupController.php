<?php

namespace App\Http\Controllers;

use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Models\Admin\Permission;
use Illuminate\Http\Request;

class UserGroupController extends Controller
{
    /**
     * Build grouped permissions for the form.
     */
    private function getGroupedPermissions(): array
    {
        $all = Permission::orderBy('label')->get()->keyBy('key');

        $groups = [
            'Catalog' => ['manage_catalog', 'manage_products', 'manage_categories', 'manage_manufacturers', 'manage_options'],
            'Sales' => ['manage_sales', 'manage_orders', 'manage_order_statuses'],
            'Marketplace API' => ['manage_marketplace_api', 'manage_shopee', 'manage_shopee_orders', 'manage_lazada', 'manage_lazada_orders', 'manage_opencart', 'manage_opencart_orders'],
            'Settings' => ['manage_settings', 'manage_users', 'manage_user_groups', 'manage_website_settings', 'manage_error_log'],
        ];

        $result = [];
        foreach ($groups as $label => $keys) {
            $perms = [];
            foreach ($keys as $key) {
                if ($all->has($key)) {
                    $perms[] = $all->get($key);
                }
            }
            if ($perms) {
                $result[] = ['label' => $label, 'permissions' => $perms];
            }
        }

        return $result;
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

        $group->name = $request->name;
        $group->save();
        $group->permissions()->sync($request->permissions ?? []);

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

        $group->delete();

        return redirect()->route('user_groups.index');
    }
}
