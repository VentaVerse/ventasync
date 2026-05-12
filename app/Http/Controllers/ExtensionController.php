<?php

namespace App\Http\Controllers;

use App\Extensions\ExtensionManager;
use App\Models\Extension;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use ZipArchive;

class ExtensionController extends Controller
{
    public function index(ExtensionManager $manager)
    {
        $extensions = collect($manager->all())->all();

        return view('extensions.index', [
            'extensions' => $extensions,
            'domain' => request()->getHost(),
        ]);
    }

    public function toggle(Request $request, ExtensionManager $manager, string $id)
    {
        $extension = Extension::find($id);

        if (!$extension) {
            return back()->with('error', "Extension '{$id}' is not installed.");
        }

        if ($extension->enabled) {
            $manager->disable($id);
            return back()->with('success', "Extension '{$extension->name}' disabled.");
        }

        $manager->enable($id);
        return back()->with('success', "Extension '{$extension->name}' enabled.");
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
