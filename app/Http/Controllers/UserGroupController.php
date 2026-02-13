<?php

namespace App\Http\Controllers;

use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Models\Admin\Permission;
use Illuminate\Http\Request;

class UserGroupController extends Controller
{
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
        $permissions = Permission::whereIn('key', ['manage_catalog','manage_marketplace_api','manage_settings'])->orderBy('label')->get();
        return view('user_groups.create', compact('permissions'));
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
        $permissions = Permission::whereIn('key', ['manage_catalog','manage_marketplace_api','manage_settings'])->orderBy('label')->get();
        $selected = $group->permissions()->pluck('permissions.id')->all();

        return view('user_groups.edit', compact('group','permissions','selected'));
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
