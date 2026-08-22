<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The screen that decides who can delete a live marketplace listing.
 *
 * Everything here was found by measuring the real page, and every one of them
 * failed silently - which is the property that makes this surface dangerous.
 * A permission mistake is not discovered by the person who makes it; it is
 * discovered days later by the person who lost a menu.
 */
class UserGroupPermissionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Install every extension on disk, because production has them installed
     * and the permission tree only places a key whose owner is there.
     * Without this the audit extension's manage_activity_log is a row with no
     * section, and the coverage assertion below reports a defect that only
     * exists in the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);

        foreach (array_keys($manager->getManifests()) as $id) {
            try {
                $manager->install($id);
                $manager->enable($id);
            } catch (\Throwable) {
                // An extension that cannot install here (missing tables, say)
                // simply contributes no permissions, which the coverage
                // assertion already tolerates via orphanedPermissionKeys.
            }
        }
    }

    private function admin(): User
    {
        // firstOrCreate: the migrations seed an Administrator group, and the
        // safeguard under test keys on that exact name.
        $group = UserGroup::firstOrCreate(['name' => 'Administrator']);
        $group->permissions()->sync(Permission::pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function groupWith(string $name, array $keys): UserGroup
    {
        $group = UserGroup::create(['name' => $name]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return $group;
    }

    /**
     * A REFUSED DELETE MUST NOT HAVE ALREADY DESTROYED THE GROUP.
     *
     * detach() ran before the assigned-users count, so refusing the delete
     * left the group standing with every permission stripped. Reproduced on
     * real data: Fulfilment, 14 permissions to 0, while the operator read
     * "Cannot delete this group because there are 1 user(s) assigned to it"
     * and reasonably concluded nothing had happened.
     */
    public function test_refusing_to_delete_a_group_leaves_its_permissions_alone(): void
    {
        $group = $this->groupWith('Fulfilment', ['view_products', 'manage_products']);
        User::factory()->create(['user_group_id' => $group->id]);

        $before = $group->permissions()->count();
        $this->assertGreaterThan(0, $before);

        $this->actingAs($this->admin())
            ->delete(route('user_groups.destroy', $group->id))
            ->assertRedirect(route('user_groups.index'))
            ->assertSessionHas('error');

        $this->assertNotNull(UserGroup::find($group->id), 'the group must survive a refused delete');
        $this->assertSame($before, $group->fresh()->permissions()->count(),
            'a refused delete must not strip the permissions of a group people are still in');
    }

    public function test_a_group_nobody_is_in_still_deletes(): void
    {
        $group = $this->groupWith('Empty', ['view_products']);

        $this->actingAs($this->admin())
            ->delete(route('user_groups.destroy', $group->id))
            ->assertRedirect(route('user_groups.index'));

        $this->assertNull(UserGroup::find($group->id));
        $this->assertSame(0, DB::table('user_group_permissions')->where('user_group_id', $group->id)->count());
    }

    /**
     * THE SAFEGUARD MUST TEST THE NAME IT IS DEFENDING.
     *
     * It ran after $group->name was reassigned, so it read the SUBMITTED
     * name: renaming Administrator and pressing "Off all" in the same save
     * stripped the only group holding manage_user_groups, and nobody could
     * reach this page again. A guard a single field edit switches off is not
     * a guard.
     */
    public function test_renaming_the_administrator_group_does_not_defeat_its_safeguard(): void
    {
        $actor = $this->admin();
        $group = UserGroup::where('name', 'Administrator')->firstOrFail();

        $this->actingAs($actor)
            ->put(route('user_groups.update', $group->id), [
                'name' => 'Admins',
                'permissions' => [],
            ])->assertRedirect();

        $kept = $group->fresh()->permissions()->pluck('key');

        foreach (['manage_settings', 'manage_user_groups', 'manage_users'] as $core) {
            $this->assertContains($core, $kept->all(),
                "renaming the group must not let it drop {$core} and lock everyone out");
        }
    }

    /**
     * WHAT ACCESS MOVED HAS TO BE ANSWERABLE ON MONDAY.
     *
     * ActivityLogger::diff was called with ['name'], so renaming a group was
     * recorded and handing somebody every permission in the application was
     * not - on the screen that grants the ability to delete a live listing.
     */
    public function test_the_audit_trail_records_which_permissions_moved(): void
    {
        $group = $this->groupWith('Fulfilment', ['view_products']);
        $grant = Permission::where('key', 'view_orders')->value('id');

        $this->actingAs($this->admin())
            ->put(route('user_groups.update', $group->id), [
                'name' => 'Fulfilment',
                'permissions' => [$grant],
            ])->assertRedirect();

        $entry = DB::table('activity_logs')
            ->where('subject_type', 'User Group')
            ->where('subject_id', $group->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($entry, 'the update must be logged at all');

        $changes = json_decode((string) $entry->changes, true) ?: [];

        $this->assertArrayHasKey('permissions_granted', $changes);
        $this->assertArrayHasKey('permissions_revoked', $changes);
        $this->assertContains('view_orders', $changes['permissions_granted']);
        $this->assertContains('view_products', $changes['permissions_revoked'],
            'a revocation is the half that matters and it has to be named');
    }

    /**
     * EVERY PERMISSION THE DATABASE HOLDS NEEDS A CONTROL.
     *
     * The twin-collapse folded the view_<X> parent in as a row and dropped
     * the manage_<X> twin, so thirteen permissions - every domain-wide
     * Manage among them - had no control anywhere on the page. The row read
     * "Catalog (View All) [Off | View]" for a group holding manage_catalog,
     * which is not an incomplete statement but a false one.
     */
    public function test_every_declared_permission_can_be_set_from_the_page(): void
    {
        $actor = $this->admin();
        $manager = $this->app->make(ExtensionManager::class);

        // Orphans are keys nothing on disk declares; they are a separate
        // defect with its own command and must not mask this one.
        $orphans = array_flip($manager->orphanedPermissionKeys());

        $html = $this->actingAs($actor)
            ->get(route('user_groups.create'))
            ->assertOk()
            ->getContent();

        $missing = [];

        foreach (Permission::orderBy('key')->get() as $permission) {
            if (isset($orphans[$permission->key])) {
                continue;
            }

            // A row binds its ids into the pill's segment markup.
            if (! str_contains($html, '"' . $permission->id . '"')) {
                $missing[] = $permission->key;
            }
        }

        $this->assertSame([], $missing, sprintf(
            "These permissions exist in the database and have no control on the page: %s\n\n"
            ."A permission that can be granted and cannot be seen is one nobody can audit.",
            implode(', ', $missing)
        ));
    }

    /**
     * The counter has to be able to reach its own denominator.
     *
     * $totalPills was incremented once per row and again for every folded
     * twin, so a fully granted Administrator displayed 37 / 44 and could
     * never read full - which teaches the operator to disbelieve the only
     * progress indicator on the page.
     */
    public function test_the_permission_counter_counts_the_controls_that_render(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('user_groups.create'))
            ->assertOk()
            ->getContent();

        preg_match('/data-total="(\d+)"/', $html, $m);
        $this->assertNotEmpty($m, 'the picker must publish a total');

        $rendered = substr_count($html, 'class="permv2-pill"');

        $this->assertSame((int) $m[1], $rendered,
            'the denominator must equal the number of pills on screen, or it can never be reached');
    }

    /**
     * The dark theme override has to be able to match.
     *
     * It was scoped to `.x-app`, a class only two layouts emit, while every
     * user-group view extends layouts.blotter and gets `bl-app`. The rule
     * never fired, so the ACTIVE segment - the only thing saying what access
     * a group has - rendered white on a pale thumb: measured 1.58:1 on
     * manage, against a comment in the same file claiming 8.50:1.
     */
    public function test_the_permission_pill_dark_override_is_not_scoped_to_a_class_the_page_lacks(): void
    {
        $css = File::get(resource_path('css/components/permission-pill.css'));

        /* Comments stripped first. This file now explains at length WHY the
           selector must not say .x-app, and asserting on the raw text would
           fail on the explanation - the same trap as a design check flagging
           an <img> inside a comment. */
        $rules = preg_replace('#/\*.*?\*/#s', '', $css);

        $this->assertStringNotContainsString('.x-app', $rules,
            'permission-pill.css is used only by pages extending layouts.blotter, whose body class '
            ."is bl-app. A rule scoped to .x-app cannot fire there.\n"
            .'If a channel-scoped variant is ever needed, scope it to bl-app or to the picker id.');

        $layout = File::get(resource_path('views/layouts/blotter.blade.php'));
        $this->assertStringContainsString('bl-app', $layout,
            'the assertion above is only meaningful while this is the class the layout emits');
    }

    /**
     * A permission nothing declares can be found, which it could not before.
     *
     * syncPermissions only inserts, and uninstall deletes by the manifest's
     * CURRENT keys, so a key dropped from a manifest is unreachable by both.
     * Four were found that way, left behind by a removed messages feature.
     */
    public function test_a_permission_no_manifest_declares_is_reported(): void
    {
        $manager = $this->app->make(ExtensionManager::class);

        Permission::create(['key' => 'view_a_feature_that_no_longer_exists']);

        $this->assertContains('view_a_feature_that_no_longer_exists', $manager->orphanedPermissionKeys());

        // And a real one is not swept up with it.
        $this->assertNotContains('manage_products', $manager->orphanedPermissionKeys());
    }

    /**
     * A labelled key is a key somebody meant. manage_users_groups is in no
     * config and no manifest - it is a legacy alias User::hasPermission still
     * honours - but it carries a label, and that is the difference between
     * kept on purpose and left behind.
     */
    public function test_a_key_that_carries_a_label_is_not_treated_as_abandoned(): void
    {
        $manager = $this->app->make(ExtensionManager::class);

        /* manage_users_groups is the real case: no config entry, no manifest,
         * a legacy alias User::hasPermission still honours for pre-rebuild
         * groups - and a label. Asserted against the live lang file rather
         * than a fixture, so removing that label makes this fail and say so. */
        $labelled = array_keys((array) trans('permissions'));

        $this->assertContains('manage_users_groups', $labelled,
            'this test is only meaningful while a labelled-but-undeclared key exists to protect');

        Permission::firstOrCreate(['key' => 'manage_users_groups']);
        Permission::create(['key' => 'view_a_feature_with_no_label']);

        $orphans = $manager->orphanedPermissionKeys();

        $this->assertContains('view_a_feature_with_no_label', $orphans,
            'a key nothing declares and nothing labels is abandoned');

        $this->assertNotContains('manage_users_groups', $orphans,
            'a key that carries a label was meant, and pruning it would silently drop a compatibility alias');
    }

    public function test_pruning_removes_the_permission_and_the_grants_pointing_at_it(): void
    {
        $manager = $this->app->make(ExtensionManager::class);

        $dead = Permission::create(['key' => 'manage_a_removed_feature']);
        $group = $this->groupWith('Holder', ['view_products']);
        $group->permissions()->attach($dead->id);

        $this->assertSame(1, DB::table('user_group_permissions')
            ->where('permission_id', $dead->id)->count());

        $removed = $manager->pruneOrphanedPermissions();

        $this->assertGreaterThanOrEqual(1, $removed);
        $this->assertNull(Permission::find($dead->id));
        $this->assertSame(0, DB::table('user_group_permissions')
            ->where('permission_id', $dead->id)->count(),
            'a deleted permission must not leave a grant pointing at nothing');
    }
}
