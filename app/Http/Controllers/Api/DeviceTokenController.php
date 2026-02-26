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
            'token'    => 'required|string',
            'platform' => 'sometimes|string|in:android,ios',
        ]);

        DeviceToken::updateOrCreate(
            ['token' => $request->token],
            [
                'user_id'  => $request->user()->id,
                'platform' => $request->input('platform', 'android'),
            ]
        );

        return response()->json(['message' => 'Token registered.']);
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        DeviceToken::where('token', $request->token)->delete();

        return response()->json(['message' => 'Token removed.']);
    }
}
