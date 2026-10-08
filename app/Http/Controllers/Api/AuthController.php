<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AppDoor;
use App\Support\Auth\AccountLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('username', $request->username)->first();

        if ($user && AccountLock::isLocked($user)) {
            throw ValidationException::withMessages(['username' => [AccountLock::message($user)]]);
        }

        if (! $user || ! Hash::check($request->password, $user->password)) {
            if ($user) {
                AccountLock::failed($user);
                if (AccountLock::isLocked($user->fresh())) {
                    throw ValidationException::withMessages(['username' => [AccountLock::message($user->fresh())]]);
                }
            }
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        AccountLock::clear($user);

        if (($refused = AppDoor::refusal($user)) !== null) {
            return response()->json(['message' => $refused], 403);
        }

        $user->tokens()->where('name', 'ventasync-mobile')->delete();

        $token = $user->createToken('ventasync-mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'       => $user->id,
                'name'     => $user->name,
                'username' => $user->username,
            ],
            'permissions' => $user->getEffectivePermissions(),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
