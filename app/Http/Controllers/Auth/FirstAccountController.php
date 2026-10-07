<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\FirstAccountRequest;
use App\Models\Setting;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Services\ActivityLogger;
use App\Support\FirstAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class FirstAccountController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (! FirstAccount::needed()) {
            return redirect()->route('login');
        }

        return view('auth.first-account');
    }

    public function store(FirstAccountRequest $request): RedirectResponse
    {
        // Lock so two simultaneous submits cannot both create the first admin.
        $user = Cache::lock('first-account', 15)->block(10, function () use ($request) {
            if (! FirstAccount::needed()) {
                return null;
            }

            return DB::transaction(function () use ($request) {
                $user = User::create([
                    'name' => $request->input('name'),
                    'username' => $request->input('username'),
                    'email' => $request->input('email'),
                    'user_group_id' => DB::table('user_groups')->where('name', 'Administrator')->value('id'),
                    'password' => Hash::make($request->input('password')),
                ]);

                if (filled($request->input('company_name'))) {
                    Setting::singleton()->update(['company_name' => $request->input('company_name')]);
                }

                return $user;
            });
        });

        if ($user === null) {
            return redirect()->route('login');
        }

        Auth::login($user);
        $request->session()->regenerate();
        ActivityLogger::log('created', 'User', $user->id, $user->name . ' (first account)');

        return redirect(RouteServiceProvider::HOME);
    }
}
