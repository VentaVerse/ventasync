<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MasterCatalogWordingTest extends TestCase
{
    private const CHANNELS = ['shopee', 'lazada', 'tiktok', 'ventacart', 'woocommerce', 'opencart', 'shopify', 'pedallion'];

    // Lines where "Catalog" is not the Master Catalog: the marketplace's own API scope and category names.
    private const ALLOWED = [
        "'catalog' => 'Catalog'",
        '<div class="api-cat">Catalog</div>',
    ];

    public function test_channel_pages_say_master_catalog_never_a_bare_catalog(): void
    {
        $files = [];
        foreach (self::CHANNELS as $channel) {
            if (! is_file(base_path("extensions/{$channel}/extension.json"))) {
                continue;
            }
            $root = base_path("extensions/{$channel}/views");
            $this->assertDirectoryExists($root);
            foreach (File::allFiles($root) as $file) {
                $files[] = $file->getPathname();
            }
        }
        foreach (['channel-*.blade.php', 'catalog-change-*.blade.php'] as $pattern) {
            $files = array_merge($files, glob(resource_path("views/partials/{$pattern}")) ?: []);
        }
        $this->assertGreaterThan(50, count($files));

        $bare = [];
        foreach ($files as $path) {
            foreach (preg_split('/\R/', File::get($path)) as $i => $line) {
                if (str_contains($line, '{{--') || $this->allowed($line)) {
                    continue;
                }
                $text = str_replace('Master Catalog', '', $line);
                if (preg_match('/(?<![\w.\/\\\\$\-\[:@\'"])[Cc]atalog(?:ue)?(?![\w.\/\\\\(\-\[:\'"])/', $text)) {
                    $bare[] = str_replace(base_path() . '/', '', $path) . ':' . ($i + 1) . '  ' . trim($line);
                }
            }
        }

        $this->assertSame([], $bare, "A channel page names the catalog without \"Master\":\n  " . implode("\n  ", $bare));
    }

    private function allowed(string $line): bool
    {
        foreach (self::ALLOWED as $allowed) {
            if (str_contains($line, $allowed)) {
                return true;
            }
        }

        return (bool) preg_match('/\bCatalog\s*>/', $line);
    }
}
