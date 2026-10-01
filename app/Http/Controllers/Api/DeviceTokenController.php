<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'token'       => 'required|string|max:255',
            'platform'    => 'sometimes|string|in:android,ios',
            'device_name' => 'sometimes|nullable|string|max:80',
            'os_version'  => 'sometimes|nullable|string|max:40',
            'app_version' => 'sometimes|nullable|string|max:20',
        ]);

        $device = DeviceToken::firstOrNew(['token' => $request->token]);
        $device->fill([
            'user_id'  => $request->user()->id,
            'platform' => $request->input('platform', $device->platform ?? 'android'),
        ]);
        // A phone that does not say what it is keeps what it said before.
        foreach (['device_name', 'os_version', 'app_version'] as $field) {
            if ($request->filled($field)) {
                $device->{$field} = $request->input($field);
            }
        }
        // Registering again is the phone checking in.
        $device->touch();

        return response()->json(['message' => 'Token registered.']);
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        // A person signs out their own phone, never someone else's.
        DeviceToken::where('token', $request->token)->where('user_id', $request->user()->id)->delete();

        return response()->json(['message' => 'Token removed.']);
    }
}
