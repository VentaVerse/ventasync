<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Services\PermissionCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionEditorSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);

        foreach (array_keys($manager->getManifests()) as $id) {
            try {
                $manager->install($id);
                $manager->enable($id);
            } catch (\Throwable) {
            }
        }
    }

    private function administrator(): User
    {
        $group = UserGroup::firstOrCreate(['name' => 'Administrator']);
        $group->permissions()->sync(\App\Models\Admin\Permission::pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function editorHtml(): string
    {
        $group = UserGroup::create(['name' => 'Editor safety subject']);

        return $this->actingAs($this->administrator())
            ->get(route('user_groups.edit', $group->id))
            ->assertOk()
            ->getContent();
    }

    public function test_every_bulk_action_asks_before_it_acts(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('data-cascade="off"', $html);
        $this->assertMatchesRegularExpression(
            '/data-cascade="off"[^>]*data-confirm="/s',
            $html,
            'Off all lost its confirmation.'
        );

        foreach (['view', 'manage'] as $tier) {
            $this->assertMatchesRegularExpression(
                '/data-cascade="' . $tier . '"[^>]*data-confirm-count="/s',
                $html,
                ucfirst($tier) . ' all can widen access and must ask first.'
            );
        }

        foreach (['viewer', 'operator', 'administrator'] as $preset) {
            $this->assertMatchesRegularExpression(
                '/data-preset="' . $preset . '"[^>]*data-confirm-count="/s',
                $html,
                'The ' . $preset . ' product group replaces every area and must ask first.'
            );
        }
    }

    public function test_a_guarded_button_is_told_when_it_was_confirmed(): void
    {
        $modal = file_get_contents(base_path('resources/js/confirm-modal.js'));
        $pill = file_get_contents(base_path('resources/js/permission-pill.js'));

        $this->assertNotFalse($modal, 'confirm-modal.js is unreadable.');
        $this->assertNotFalse($pill, 'permission-pill.js is unreadable.');

        $this->assertStringContainsString(
            "CustomEvent('confirm:accepted'",
            $modal,
            'Nothing tells a guarded trigger that the operator said yes.'
        );
        $this->assertStringContainsString(
            "'confirm:accepted'",
            $pill,
            'The picker acts on the raw click, so its confirmations are decoration.'
        );
    }

    public function test_only_one_function_writes_collapse_state(): void
    {
        $pill = file_get_contents(base_path('resources/js/permission-pill.js'));
        $this->assertNotFalse($pill, 'permission-pill.js is unreadable.');

        $writes = preg_match_all('/\.dataset\.collapsed\s*=/', $pill);

        $this->assertSame(
            1,
            $writes,
            'data-collapsed is written in ' . $writes . ' places. Every write must go '
            . 'through setCollapsed() or aria-expanded drifts away from what is on screen.'
        );
    }

    public function test_the_editor_does_not_promise_a_cascade_it_cannot_perform(): void
    {
        $html = $this->editorHtml();

        $this->assertStringNotContainsString('switches everything under it off', $html);
    }

    public function test_the_page_states_one_granted_figure(): void
    {
        $user = $this->administrator();

        $html = $this->actingAs($user)
            ->get(route('user_groups.edit', $user->user_group_id))
            ->assertOk()
            ->getContent();

        $catalog = $this->app->make(PermissionCatalogue::class);
        $areas = 0;

        foreach ($catalog->grouped() as $group) {
            $areas += count($group['rows']);
        }

        $this->assertMatchesRegularExpression(
            '/Areas granted<\/span>\s*<span class="fm-stamp__v">\s*\d+ of \d+/s',
            $html,
            'The header no longer counts in the same units as the picker.'
        );
        $this->assertStringNotContainsString('>' . ($areas * 3) . '<', $html);
    }

    public function test_every_icon_the_editor_asks_for_actually_exists(): void
    {
        $icon = file_get_contents(base_path('resources/views/components/ui/icon.blade.php'));
        $this->assertNotFalse($icon, 'The icon component is unreadable.');

        $views = [
            'resources/views/settings/user_groups/edit.blade.php',
            'resources/views/settings/user_groups/partials/permissions.blade.php',
        ];

        foreach ($views as $view) {
            $source = file_get_contents(base_path($view));
            $this->assertNotFalse($source, $view . ' is unreadable.');

            preg_match_all('/<x-ui\.icon\s+name="([a-z0-9-]+)"/', $source, $m);

            foreach (array_unique($m[1]) as $name) {
                $this->assertStringContainsString(
                    "'" . $name . "'",
                    $icon,
                    'Icon "' . $name . '" has no glyph, so it renders as a square.'
                );
            }
        }
    }

    public function test_the_consequential_rows_carry_a_plain_warning(): void
    {
        $catalog = $this->app->make(PermissionCatalogue::class);

        foreach (['settings/user_group', 'settings/user', 'settings/api_client'] as $key) {
            $this->assertNotSame('', $catalog->warningFor($key),
                $key . ' hands over more than its screen and must say so.');
        }

        $this->assertNotSame('', $catalog->warningFor('lazada/api_explorer'));
        $this->assertSame('', $catalog->warningFor('lazada/settings'));
        $this->assertSame('', $catalog->warningFor('finance/petty_cash_ledger'));
        $this->assertSame('', $catalog->warningFor('settings/currency'));

        $warned = 0;

        foreach (array_keys($catalog->all()) as $key) {
            if ($catalog->warningFor($key) !== '') {
                $warned++;
            }
        }

        $this->assertLessThan(
            count($catalog->all()) / 4,
            $warned,
            'A warning on everything warns about nothing.'
        );

        foreach ([
            'resources/views/settings/user_groups/edit.blade.php',
            'resources/views/settings/user_groups/partials/permissions.blade.php',
            'resources/lang/en/permissions.php',
        ] as $file) {
            $source = file_get_contents(base_path($file));
            $this->assertNotFalse($source, $file . ' is unreadable.');
            $this->assertDoesNotMatchRegularExpression(
                '/>(\s*)Elevated(\s*)</',
                $source,
                $file . ' still renders the invented word.'
            );
        }
    }

    public function test_the_machine_facing_areas_sort_last_and_are_still_offered(): void
    {
        $catalog = $this->app->make(PermissionCatalogue::class);
        $grouped = $catalog->grouped();

        $machine = array_values(array_filter($grouped, fn ($g) => $g['machine']));
        $this->assertNotEmpty($machine, 'The mobile API is missing from the catalog again.');

        $firstMachineIndex = null;

        foreach ($grouped as $i => $group) {
            if ($group['machine']) {
                $firstMachineIndex = $i;
                break;
            }
        }

        for ($i = $firstMachineIndex; $i < count($grouped); $i++) {
            $this->assertTrue(
                $grouped[$i]['machine'],
                'A business area sorts after a machine-facing one: ' . $grouped[$i]['area']
            );
        }

        foreach ($machine as $group) {
            $this->assertNotNull($group['note'], $group['area'] . ' is unexplained.');
        }
    }

    public function test_no_row_is_named_by_a_stuttering_machine(): void
    {
        $catalog = $this->app->make(PermissionCatalogue::class);
        $seen = [];

        foreach ($catalog->grouped() as $group) {
            foreach ($group['rows'] as $row) {
                $label = $row['label'];

                $this->assertDoesNotMatchRegularExpression(
                    '/\bApi\b.*\bApi\b/',
                    $label,
                    'Row ' . $row['key'] . ' is named "' . $label . '".'
                );

                $pair = $group['area'] . '|' . $label;
                $this->assertNotContains(
                    $pair,
                    $seen,
                    'Two rows in ' . $group['area'] . ' are both called "' . $label . '".'
                );
                $seen[] = $pair;
            }
        }
    }
    public function test_no_code_asks_for_a_retired_permission_key(): void
    {
        $offenders = [];

        $scan = function (string $dir) use (&$offenders) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir)));
            foreach ($it as $file) {
                if (! preg_match('/\.(php)$/', (string) $file) || str_contains((string) $file, '/vendor/')) {
                    continue;
                }
                $source = file_get_contents((string) $file);
                if ($source === false) {
                    continue;
                }
                if (preg_match_all("/hasPermission\('((?:view|manage)_[a-z_]+)'/", $source, $m)) {
                    foreach ($m[1] as $key) {
                        if (! str_contains($key, '/')) {
                            $offenders[] = str_replace(base_path() . '/', '', (string) $file) . ' :: ' . $key;
                        }
                    }
                }
            }
        };

        $scan('app');
        $scan('extensions');
        $scan('resources');

        $this->assertSame([], array_values(array_unique($offenders)),
            'These call sites ask for retired keys; use the derived key the door enforces.');
    }
    public function test_duplicating_a_group_copies_its_permissions(): void
    {
        $admin = $this->administrator();

        $source = UserGroup::create(['name' => 'Copy source']);
        $source->permissions()->attach(
            \App\Models\Admin\Permission::whereIn('key', ['view_sales/order', 'manage_catalog/product'])->pluck('id')->all()
        );

        $this->actingAs($admin)
            ->post(route('user_groups.duplicate', $source->id))
            ->assertRedirect();

        $copy = UserGroup::where('name', 'Copy source (copy)')->first();
        $this->assertNotNull($copy, 'The copy was not created.');
        $this->assertEqualsCanonicalizing(
            ['manage_catalog/product', 'view_sales/order'],
            $copy->permissions()->pluck('key')->all()
        );

        $this->actingAs($admin)->post(route('user_groups.duplicate', $source->id));
        $this->assertNotNull(UserGroup::where('name', 'Copy source (copy 2)')->first());
    }
}
