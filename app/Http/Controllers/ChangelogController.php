<?php

namespace App\Http\Controllers;

use App\Extensions\ExtensionManager;
use App\Support\AppVersion;

class ChangelogController extends Controller
{
    public function index(ExtensionManager $extensions)
    {
        $installed = [];
        foreach ($extensions->getManifests() as $id => $manifest) {
            if (! $extensions->isEnabled($id)) {
                continue;
            }
            $installed[] = [
                'name' => $manifest['name'] ?? $id,
                'version' => $manifest['version'] ?? null,
                'releases' => AppVersion::changelog(base_path('extensions/' . $id . '/CHANGELOG.md')),
            ];
        }
        usort($installed, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return view('changelog', [
            'releases' => AppVersion::changelog(base_path('CHANGELOG.md')),
            'extensions' => $installed,
        ]);
    }
}
