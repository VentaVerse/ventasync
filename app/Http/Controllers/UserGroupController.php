<?php

namespace App\Http\Controllers;

use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Models\Admin\Permission;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class UserGroupController extends Controller
{
    /**
     * Build grouped permissions for the form.
     */
    private function getGroupedPermissions(): array
    {
        $all = Permission::orderBy('label')->get()->keyBy('key');

        $resolve = function (array $keys) use ($all): array {
            $out = [];
            foreach ($keys as $k) {
                if ($all->has($k)) {
                    $out[] = $all->get($k);
                }
            }
            return $out;
        };

        return array_filter([
            [
                'key'      => 'catalog',
                'label'    => 'Catalog',
                'parent'   => $all->get('manage_catalog'),
                'children' => $resolve(['manage_products', 'manage_categories', 'manage_manufacturers', 'manage_options']),
            ],
            [
                'key'      => 'sales',
                'label'    => 'Sales',
                'parent'   => $all->get('manage_sales'),
                'children' => $resolve(['manage_orders', 'manage_order_statuses', 'manage_reports']),
            ],
            [
                'key'       => 'marketplace',
                'label'     => 'Marketplace API',
                'parent'    => $all->get('manage_marketplace_api'),
                'subgroups' => [
                    ['label' => 'Shopee',      'children' => $resolve(['manage_shopee', 'manage_shopee_orders'])],
                    ['label' => 'Lazada',      'children' => $resolve(['manage_lazada', 'manage_lazada_orders'])],
                    ['label' => 'TikTok Shop', 'children' => $resolve(['manage_tiktok', 'manage_tiktok_orders'])],
                    ['label' => 'OpenCart',    'children' => $resolve(['manage_opencart', 'manage_opencart_orders'])],
                    ['label' => 'Pedallion',  'children' => $resolve(['manage_pedallion', 'manage_pedallion_orders'])],
                    ['label' => 'Reviews',    'children' => $resolve(['manage_reviews'])],
                ],
            ],
            [
                'key'      => 'purchasing',
                'label'    => 'Purchasing',
                'parent'   => $all->get('manage_purchasing'),
                'children' => $resolve(['manage_vendors', 'manage_purchase_orders']),
            ],
            [
                'key'      => 'warehousing',
                'label'    => 'Warehousing',
                'parent'   => $all->get('manage_warehousing'),
                'children' => [],
            ],
            [
                'key'      => 'settings',
                'label'    => 'Settings',
                'parent'   => $all->get('manage_settings'),
                'children' => $resolve(['manage_users', 'manage_user_groups', 'manage_website_settings', 'manage_error_log', 'manage_activity_log']),
            ],
        ], fn ($g) => $g['parent'] !== null);
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
