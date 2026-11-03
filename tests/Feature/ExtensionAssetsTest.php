<?php

namespace Tests\Feature;

use App\Extensions\ExtensionAssets;
use App\Extensions\ExtensionManager;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ExtensionAssetsTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = base_path('extensions/assetcheck/assets');
        File::ensureDirectoryExists($this->source);
        File::put($this->source . '/assetcheck.css', '.a{}');
        File::put($this->source . '/assetcheck.js', '');
    }

    protected function tearDown(): void
    {
        ExtensionAssets::remove('assetcheck');
        File::deleteDirectory(base_path('extensions/assetcheck'));
        parent::tearDown();
    }

    private function enabled(array $ids): void
    {
        $manager = $this->createMock(ExtensionManager::class);
        $manager->method('getEnabledIds')->willReturn($ids);
        $this->app->instance(ExtensionManager::class, $manager);
    }

    public function test_publish_copies_the_files_and_remove_deletes_them(): void
    {
        ExtensionAssets::publish('assetcheck');
        $this->assertFileExists(public_path('extension-assets/assetcheck/assetcheck.css'));

        ExtensionAssets::remove('assetcheck');
        $this->assertDirectoryDoesNotExist(public_path('extension-assets/assetcheck'));
    }

    public function test_only_an_enabled_extension_is_loaded(): void
    {
        ExtensionAssets::publish('assetcheck');

        $this->enabled(['assetcheck']);
        $this->assertCount(1, array_filter(ExtensionAssets::styles(), fn ($u) => str_contains($u, 'assetcheck.css')));
        $this->assertCount(1, array_filter(ExtensionAssets::scripts(), fn ($u) => str_contains($u, 'assetcheck.js')));

        $this->enabled([]);
        $this->assertSame([], ExtensionAssets::styles());
        $this->assertSame([], ExtensionAssets::scripts());
    }

    public function test_a_bad_id_touches_nothing(): void
    {
        ExtensionAssets::publish('../assetcheck');
        ExtensionAssets::remove('../assetcheck');

        $this->assertDirectoryDoesNotExist(public_path('assetcheck'));
    }
}
