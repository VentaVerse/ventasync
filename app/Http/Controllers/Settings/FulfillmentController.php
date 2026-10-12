<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FulfillmentController extends Controller
{
    public function edit(): View
    {
        return view('settings.fulfillment.edit', ['setting' => Setting::singleton()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'packing_check' => ['nullable', 'in:1'],
        ]);

        $setting = Setting::singleton();
        $before = (bool) $setting->packing_check;
        $after = $request->boolean('packing_check');

        $setting->update(['packing_check' => $after]);

        if ($before !== $after) {
            ActivityLogger::log('updated', 'Setting', (int) $setting->id, 'Fulfillment', [
                'packing_check' => [$before ? 'on' : 'off', $after ? 'on' : 'off'],
            ]);
        }

        return redirect()->route('settings.fulfillment')->with('status', 'Fulfillment settings saved.');
    }
}
