<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AppVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VersioningTest extends TestCase
{
    use RefreshDatabase;

    private const SEMVER = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/';

    public function test_the_core_changelog_opens_with_the_current_version(): void
    {
        $this->assertMatchesRegularExpression(self::SEMVER, AppVersion::current());
        $releases = $this->released(base_path('CHANGELOG.md'));
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

            $releases = $this->released($dir . '/CHANGELOG.md');
            $this->assertNotEmpty($releases, basename($dir) . ' has no CHANGELOG.md');
            $this->assertSame($version, $releases[0]['version'], basename($dir) . ' was raised without a changelog entry, or the other way round');
            $this->assertNotEmpty($releases[0]['lines'], basename($dir) . ' newest release says nothing');
        }
    }

    public function test_every_signed_in_person_sees_the_version_as_plain_text(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('<p class="bl-version">' . AppVersion::label(), false)
            ->assertDontSee('/changelog', false);
    }

    public function test_unreleased_lines_wait_above_the_newest_release(): void
    {
        $all = AppVersion::changelog(base_path('CHANGELOG.md'));
        foreach (array_slice($all, 1) as $release) {
            $this->assertNotSame('Unreleased', $release['version'], 'Unreleased belongs at the top only');
        }
        if (($all[0]['version'] ?? null) === 'Unreleased') {
            $this->assertNotEmpty($all[0]['lines'], 'an empty Unreleased heading says nothing');
        }
    }

    private function released(string $file): array
    {
        return array_values(array_filter(AppVersion::changelog($file), fn ($r) => $r['version'] !== 'Unreleased'));
    }

    public function test_pro_says_pro_and_community_links_to_pricing(): void
    {
        $file = base_path('EDITION');
        $original = is_file($file) ? file_get_contents($file) : null;

        try {
            file_put_contents($file, "Pro\n");
            AppVersion::forget();
            $this->assertSame('VentaSync Pro ' . AppVersion::current(), AppVersion::label());
            $this->actingAs(User::factory()->create());
            $this->get(route('dashboard'))->assertOk()->assertDontSee('Get Pro');

            unlink($file);
            AppVersion::forget();
            $this->assertSame('VentaSync ' . AppVersion::current(), AppVersion::label());
            $this->actingAs(User::factory()->create());
            $this->get(route('dashboard'))->assertOk()
                ->assertSee('href="' . config('licensing.pricing_url') . '"', false)
                ->assertSee('Get Pro');

            file_put_contents($file, "Enterprise\n");
            AppVersion::forget();
            $this->assertSame('Community', AppVersion::edition(), 'only the word Pro makes it Pro');
        } finally {
            $original === null ? @unlink($file) : file_put_contents($file, $original);
            AppVersion::forget();
        }
    }
}
