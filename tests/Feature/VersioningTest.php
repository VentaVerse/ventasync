<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AppVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VersioningTest extends TestCase
{
    use RefreshDatabase;

    private const SEMVER = '/^\d+\.\d+\.\d+$/';

    public function test_the_core_changelog_opens_with_the_current_version(): void
    {
        $this->assertMatchesRegularExpression(self::SEMVER, AppVersion::current());
        $releases = AppVersion::changelog(base_path('CHANGELOG.md'));
        $this->assertNotEmpty($releases, 'CHANGELOG.md has no release');
        $this->assertSame(AppVersion::current(), $releases[0]['version'], 'VERSION was raised without a CHANGELOG.md entry, or the other way round');
        $this->assertNotEmpty($releases[0]['lines'], 'the newest release says nothing');
    }

    public function test_every_extension_changelog_opens_with_its_manifest_version(): void
    {
        foreach (glob(base_path('extensions/*/extension.json')) as $file) {
            $dir = dirname($file);
            $manifest = json_decode((string) file_get_contents($file), true);
            $version = (string) ($manifest['version'] ?? '');
            $this->assertMatchesRegularExpression(self::SEMVER, $version, basename($dir) . ' version');

            $releases = AppVersion::changelog($dir . '/CHANGELOG.md');
            $this->assertNotEmpty($releases, basename($dir) . ' has no CHANGELOG.md');
            $this->assertSame($version, $releases[0]['version'], basename($dir) . ' was raised without a changelog entry, or the other way round');
            $this->assertNotEmpty($releases[0]['lines'], basename($dir) . ' newest release says nothing');
        }
    }

    public function test_every_signed_in_person_sees_the_version_and_its_changelog(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('VentaSync ' . AppVersion::current())
            ->assertSee(route('changelog'), false);

        $this->get(route('changelog'))->assertOk()
            ->assertSee('VentaSync ' . AppVersion::current())
            ->assertSee(AppVersion::changelog(base_path('CHANGELOG.md'))[0]['lines'][0]);
    }
}
