<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Extensions\ExtensionManager;
use App\Models\Extension;
use App\Services\ActivityLogger;
use App\Plans\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use ZipArchive;

class ExtensionController extends Controller
{
    private function isUnsafeZipEntryName(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0")) {
            return true;
        }

        $normalized = str_replace('\\', '/', $name);

        if (str_contains($normalized, '..')) {
            return true;
        }

        if (str_starts_with($normalized, '/')) {
            return true;
        }

        if (preg_match('#^[A-Za-z]:[/\\\\]#', $name)) {
            return true;
        }

        return false;
    }

    public function index(ExtensionManager $manager)
    {
        $extensions = collect($manager->all())->all();

        return view('settings.extensions.index', [
            'extensions' => $extensions,
            'domain' => request()->getHost(),
        ]);
    }

    public function toggle(Request $request, ExtensionManager $manager, string $extension)
    {
        $row = Extension::find($extension);

        if (!$row) {
            return back()->with('error', "Extension '{$extension}' is not installed.");
        }

        if ($row->enabled && Plan::allowsExtension($extension)) {
            $manager->disable($extension);
            return back()->with('success', "Extension '{$row->name}' disabled.");
        }

        if (!$manager->enable($extension)) {
            return back()->with('error', "{$row->name} is not in your plan.");
        }
        return back()->with('success', "Extension '{$row->name}' enabled.");
    }

    public function install(Request $request, ExtensionManager $manager)
    {
        abort_unless(Plan::allowsUploads(), 403);

        $request->validate([
            'file' => 'required|file|max:51200',
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();

        $allowedExtensions = ['zip', 'erpx'];
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, $allowedExtensions)) {
            return back()->with('error', 'Only .zip and .erpx files are accepted.');
        }

        $tmpDir = storage_path('app/tmp-ext-' . uniqid());
        File::makeDirectory($tmpDir, 0755, true);

        $zip = new ZipArchive();
        $zipPath = $file->getRealPath();

        if ($zip->open($zipPath) !== true) {
            File::deleteDirectory($tmpDir);
            return back()->with('error', 'Could not open the archive file.');
        }

        // Reject zip entries that escape the extraction directory before writing anything (zip slip).
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName !== false && $this->isUnsafeZipEntryName($entryName)) {
                $zip->close();
                File::deleteDirectory($tmpDir);
                abort(422, 'The archive contains an unsafe file path.');
            }
        }

        $zip->extractTo($tmpDir);
        $zip->close();

        $realTmpDir = realpath($tmpDir);
        if ($realTmpDir === false) {
            File::deleteDirectory($tmpDir);
            abort(422, 'Could not read the extracted archive.');
        }

        // Confirm every extracted file landed inside the temp directory.
        foreach (File::allFiles($tmpDir) as $extractedFile) {
            $realExtracted = realpath($extractedFile->getPathname());
            if ($realExtracted === false || !str_starts_with($realExtracted, $realTmpDir . DIRECTORY_SEPARATOR)) {
                File::deleteDirectory($tmpDir);
                abort(422, 'The archive contains an unsafe file path.');
            }
        }

        $manifestPath = null;
        $manifestDir = null;

        if (File::exists($tmpDir . '/extension.json')) {
            $manifestPath = $tmpDir . '/extension.json';
            $manifestDir = $tmpDir;
        } else {
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
            return back()->with('error', 'Invalid extension.json: missing "id" field.');
        }

        $extensionId = $manifest['id'];
        if (!is_string($extensionId) || !preg_match('/^[a-z0-9_-]+$/', $extensionId)) {
            File::deleteDirectory($tmpDir);
            abort(422, 'Invalid extension.json: "id" may only contain lowercase letters, numbers, hyphens, and underscores.');
        }

        $destDir = base_path('extensions/' . $extensionId);

        $extensionsRoot = realpath(base_path('extensions'));
        if ($extensionsRoot === false || realpath(dirname($destDir)) !== $extensionsRoot) {
            File::deleteDirectory($tmpDir);
            abort(422, 'Invalid extension destination.');
        }

        if (File::isDirectory($destDir)) {
            File::deleteDirectory($destDir);
        }

        File::copyDirectory($manifestDir, $destDir);
        File::deleteDirectory($tmpDir);

        $manager->install($extensionId);

        Artisan::call('migrate', ['--force' => true]);

        return back()->with('success', "Extension '{$manifest['name']}' installed successfully.");
    }

    public function reinstall(ExtensionManager $manager, string $extension)
    {
        if (!$manager->install($extension)) {
            return back()->with('error', "Extension '{$extension}' is not in your plan.");
        }

        Artisan::call('migrate', ['--force' => true]);

        return back()->with('success', "Extension '{$extension}' installed.");
    }

    public function refresh()
    {
        \App\Support\AppCaches::refresh();

        return back()->with('success', 'Refreshed.');
    }

    public function uninstall(ExtensionManager $manager, string $extension)
    {
        $row = Extension::find($extension);
        $name = $row->name ?? $extension;

        $manager->uninstall($extension, false);

        return back()->with('success', "Extension '{$name}' uninstalled.");
    }

}
