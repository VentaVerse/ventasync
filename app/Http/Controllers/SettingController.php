<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    public function edit()
    {
        $setting = Setting::singleton();
        return view('settings.edit', compact('setting'));
    }

    public function update(Request $request)
    {
        $setting = Setting::singleton();

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'from_email'   => ['required', 'email', 'max:255'],
            'timezone'     => ['required', 'string', 'timezone'],
            'logo'           => ['nullable', 'image', 'max:2048'],
            'software_logo' => ['nullable', 'image', 'max:2048'],
        ]);

        if ($request->hasFile('logo')) {
            if ($setting->logo_path) {
                Storage::disk('public')->delete($setting->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('company', 'public');
        }


        if ($request->hasFile('software_logo')) {
            if ($setting->software_logo_path) {
                Storage::disk('public')->delete($setting->software_logo_path);
            }
            $data['software_logo_path'] = $request->file('software_logo')->store('software', 'public');
        }

        $setting->update($data);

        return back()->with('success', 'Settings updated.');
    }
}
