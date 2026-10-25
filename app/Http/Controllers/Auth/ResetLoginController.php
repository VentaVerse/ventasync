<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetLoginRequest;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Auth\ResetLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ResetLoginController extends Controller
{
    public function create(Request $request, User $user): Response
    {
        if (! ResetLink::valid($request, $user)) {
            return response()->view('auth.reset-login', ['expired' => true], 403);
        }

        return response()->view('auth.reset-login', ['expired' => false, 'user' => $user]);
    }

    public function store(ResetLoginRequest $request, User $user): RedirectResponse
    {
        $before = $user->username;
        $user->forceFill([
            'username' => $request->input('username'),
            'password' => Hash::make($request->input('password')),
            'remember_token' => Str::random(60),
        ])->save();

        ActivityLogger::log('password_reset', 'User', (int) $user->id, $user->username,
            $before !== $user->username ? ['username' => [$before, $user->username]] : null, 'system');

        return redirect()->route('login')->with('status', 'Password changed.');
    }
}
