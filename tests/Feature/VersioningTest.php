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
            ->assertSee(AppVersion::label())
            ->assertSee(route('changelog'), false);

        $this->get(route('changelog'))->assertOk()
            ->assertSee(AppVersion::label())
            ->assertSee(AppVersion::changelog(base_path('CHANGELOG.md'))[0]['lines'][0]);
    }

    public function test_the_edition_file_says_pro_and_its_absence_says_community(): void
    {
        $file = base_path('EDITION');
        $original = is_file($file) ? file_get_contents($file) : null;

        try {
            file_put_contents($file, "Pro\n");
            AppVersion::forget();
            $this->assertSame('VentaSync Pro ' . AppVersion::current(), AppVersion::label());

            unlink($file);
            AppVersion::forget();
            $this->assertSame('VentaSync Community ' . AppVersion::current(), AppVersion::label());

            file_put_contents($file, "Enterprise\n");
            AppVersion::forget();
            $this->assertSame('Community', AppVersion::edition(), 'only the word Pro makes it Pro');
        } finally {
            $original === null ? @unlink($file) : file_put_contents($file, $original);
            AppVersion::forget();
        }
    }
}
