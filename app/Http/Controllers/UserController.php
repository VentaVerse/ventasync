<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Admin\UserGroup;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q',''));

        $users = User::query()
            ->with('userGroup')
            ->when($q !== '', function ($query) use ($q) {
                $query->where('name','like','%'.$q.'%')
                    ->orWhere('email','like','%'.$q.'%')
                    ->orWhere('username','like','%'.$q.'%');
            })
            ->orderBy('id','desc')
            ->paginate(50)
            ->withQueryString();

        return view('users.index', compact('users','q'));
    }

    public function create()
    {
        $groups = UserGroup::orderBy('name')->get();
        return view('users.create', compact('groups'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            'user_group_id' => 'required|integer|exists:user_groups,id',
        ]);

        $user = User::create([
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'user_group_id' => (int) $request->user_group_id,
            'password' => Hash::make($request->password),
        ]);

        ActivityLogger::log('created', 'User', $user->id, $user->name . ' (' . $user->email . ')');

        return redirect()->route('users.index')->with('status','User saved');
    }

    public function edit($id)
    {
        $user = User::findOrFail((int)$id);
        $groups = UserGroup::orderBy('name')->get();

        return view('users.edit', compact('user','groups'));
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail((int)$id);

        $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username,'.$user->id,
            'email' => 'required|email|max:255|unique:users,email,'.$user->id,
            'user_group_id' => 'required|integer|exists:user_groups,id',
            'password' => 'nullable|string|min:6|confirmed',
        ]);

        $original = $user->getAttributes();

        $user->name = $request->name;
        $user->username = $request->username;
        $user->email = $request->email;
        $user->user_group_id = (int) $request->user_group_id;

        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();

        $changes = ActivityLogger::diff($original, $user->getAttributes(), ['name', 'username', 'email', 'user_group_id']);
        ActivityLogger::log('updated', 'User', $user->id, $user->name . ' (' . $user->email . ')', $changes);

        return redirect()->route('users.index')->with('status','User updated');
    }

    public function destroy($id)
    {
        $user = User::findOrFail((int)$id);

        // avoid deleting yourself accidentally (simple safety)
        if (auth()->check() && auth()->id() === $user->id) {
            return redirect()->route('users.index')->with('error', 'You cannot delete your own account');
        }

        // Protect administrators: Administrator accounts cannot be deleted.
        // If someone resigns, change their user group first, then delete.
        $adminGroupId = UserGroup::where('name', 'Administrator')->value('id');
        if ($adminGroupId && (int) $user->user_group_id === (int) $adminGroupId) {
            return redirect()->route('users.index')
                ->with('error', 'Administrator accounts cannot be deleted. Change the user group first if you need to remove access.');
        }

        $userName = $user->name;
        $user->delete();

        ActivityLogger::log('deleted', 'User', (int) $id, $userName);

        return redirect()->route('users.index')->with('status','User deleted');
    }
}
