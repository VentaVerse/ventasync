<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EditionSplitTest extends TestCase
{
    private const COMMUNITY = ['lazada', 'opencart', 'shopee', 'tiktok', 'ventacart'];

    private function manifests(): array
    {
        $out = [];
        foreach (glob(base_path('extensions/*/extension.json')) as $file) {
            $manifest = json_decode((string) file_get_contents($file), true);
            $this->assertIsArray($manifest, $file);
            $out[basename(dirname($file))] = $manifest;
        }

        return $out;
    }

    public function test_every_extension_names_its_edition_and_community_is_the_five_channels(): void
    {
        $community = [];
        foreach ($this->manifests() as $id => $manifest) {
            $this->assertContains($manifest['tier'] ?? null, ['community', 'plus'], $id . ' names no edition');
            if ($manifest['tier'] === 'community') {
                $community[] = $id;
            }
        }
        sort($community);

        $this->assertSame(self::COMMUNITY, $community);
    }

    public function test_a_community_extension_never_names_a_pro_extension(): void
    {
        $pro = array_keys(array_filter($this->manifests(), fn ($m) => ($m['tier'] ?? null) === 'plus'));
        $offenders = [];

        foreach (self::COMMUNITY as $id) {
            foreach (File::allFiles(base_path('extensions/' . $id)) as $file) {
                $source = strtolower((string) file_get_contents($file->getPathname()));
                foreach ($pro as $other) {
                    if (str_contains($source, 'extensions\\' . $other . '\\') || str_contains($source, 'ext-' . $other . '::')) {
                        $offenders[] = $id . '/' . $file->getRelativePathname() . ' names ' . $other;
                    }
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
