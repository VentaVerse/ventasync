<?php

namespace App\Http\Controllers;

use App\Extensions\ExtensionManager;
use App\Models\Extension;
use App\Services\ActivityLogger;
use App\Services\LicenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use ZipArchive;

class ExtensionController extends Controller
{
    public function index(ExtensionManager $manager, LicenseService $license)
    {
        $extensions = collect($manager->all())->map(function ($ext) use ($license) {
            $ext['license_status'] = 'free';
            $ext['license_label'] = 'Free';

            if (!empty($ext['license_required'])) {
                $stored = $license->getStoredLicense($ext['id']);
                if (!$stored) {
                    $ext['license_status'] = 'unactivated';
                    $ext['license_label'] = 'Unlicensed';
                } elseif ($stored->is_revoked) {
                    $ext['license_status'] = 'revoked';
                    $ext['license_label'] = 'Revoked';
                } elseif ($stored->isExpired()) {
                    $ext['license_status'] = 'expired';
                    $ext['license_label'] = 'Expired';
                } elseif ($stored->isTrial()) {
                    $ext['license_status'] = 'trial';
                    $ext['license_label'] = 'Trial';
                } elseif ($license->isModuleLicensed($ext['id'])) {
                    $ext['license_status'] = 'licensed';
                    $ext['license_label'] = 'Licensed';
                }
            }
            return $ext;
        })->all();

        return view('extensions.index', compact('extensions'));
    }

    public function toggle(Request $request, ExtensionManager $manager, string $id)
    {
        $extension = Extension::find($id);

        if (!$extension) {
            return back()->with('error', "Extension '{$id}' is not installed.");
        }

        if ($extension->enabled) {
            $manager->disable($id);
            $action = 'disabled';
        } else {
            $manager->enable($id);
            $action = 'enabled';
        }

        return back()->with('success', "Extension '{$extension->name}' {$action}.");
    }

    public function install(Request $request, ExtensionManager $manager)
    {
        $request->validate([
            'file' => 'required|file|max:51200', // 50 MB max
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();

        // Validate extension
        $allowedExtensions = ['zip', 'erpx'];
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, $allowedExtensions)) {
            return back()->with('error', 'Only .zip and .erpx files are accepted.');
        }

        // Extract to temp directory
        $tmpDir = storage_path('app/tmp-ext-' . uniqid());
        File::makeDirectory($tmpDir, 0755, true);

        $zip = new ZipArchive();
        $zipPath = $file->getRealPath();

        if ($zip->open($zipPath) !== true) {
            File::deleteDirectory($tmpDir);
            return back()->with('error', 'Could not open the archive file.');
        }

        $zip->extractTo($tmpDir);
        $zip->close();

        // Find extension.json — may be at root or one level deep
        $manifestPath = null;
        $manifestDir = null;

        if (File::exists($tmpDir . '/extension.json')) {
            $manifestPath = $tmpDir . '/extension.json';
            $manifestDir = $tmpDir;
        } else {
            // Check one level deep
            $dirs = File::directories($tmpDir);
            foreach ($dirs as $dir) {
                if (File::exists($dir . '/extension.json')) {
                    $manifestPath = $dir . '/extension.json';
                    $manifestDir = $dir;
                    break;
                }
            }
        }

        if (!$manifestPath) {
            File::deleteDirectory($tmpDir);
            return back()->with('error', 'No extension.json found in the archive.');
        }

        $manifest = json_decode(File::get($manifestPath), true);

        if (!is_array($manifest) || empty($manifest['id'])) {
            File::deleteDirectory($tmpDir);
            return back()->with('error', 'Invalid extension.json — missing "id" field.');
        }

        $extensionId = $manifest['id'];
        $destDir = base_path('extensions/' . $extensionId);

        // Copy files to extensions/{id}/
        if (File::isDirectory($destDir)) {
            File::deleteDirectory($destDir);
        }

        File::copyDirectory($manifestDir, $destDir);
        File::deleteDirectory($tmpDir);

        // Install via manager (creates/updates DB record)
        $manager->install($extensionId);

        // Run migrations
        Artisan::call('migrate', ['--force' => true]);

        return back()->with('success', "Extension '{$manifest['name']}' installed successfully.");
    }

    public function reinstall(ExtensionManager $manager, string $id)
    {
        $manager->install($id);

        Artisan::call('migrate', ['--force' => true]);

        return back()->with('success', "Extension '{$id}' installed.");
    }

    public function uninstall(ExtensionManager $manager, string $id)
    {
        $extension = Extension::find($id);
        $name = $extension->name ?? $id;

        $manager->uninstall($id, false);

        return back()->with('success', "Extension '{$name}' uninstalled.");
    }

}
