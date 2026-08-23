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
        $catalogue = $this->app->make(\App\Services\PermissionCatalogue::class);

        $html = $this->actingAs($actor)
            ->get(route('user_groups.create'))
            ->assertOk()
            ->getContent();

        /* THE CATALOGUE IS THE CONTRACT, not every row in the table.
         *
         * The table holds both schemes during the changeover: the old
         * hand-written keys, which still open the doors they used to and are
         * being retired, and the route-derived ones the editor renders. A
         * permission that CAN be granted here has to be visible here - that
         * is what this guards - and the old rows are deliberately not on
         * offer, because offering a key nothing derives is how the previous
         * list drifted in the first place. */
        $missing = [];

        foreach ($catalogue->keys() as $key) {
            if (! str_contains($html, 'data-perm-key="' . $key . '"')) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing, sprintf(
            "These permissions are derived from the routes and have no control on the page: %s\n\n"
            ."A permission that can be granted and cannot be seen is one nobody can audit.",
            implode(', ', $missing)
        ));
    }

    /**
     * And the old scheme is deliberately absent, not accidentally missing.
     *
     * Retiring those keys is a later, separate step - they still open the
     * doors they always did through the transition shim - but they must not
     * appear on this screen, or an operator would be setting a key that the
     * catalogue no longer derives and nothing would say which of the two
     * mattered.
     */
    public function test_the_retired_key_scheme_is_not_offered(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('user_groups.create'))
            ->assertOk()
            ->getContent();

        foreach (['manage_products', 'view_products', 'manage_lazada', 'manage_lazada/dashboard', 'view_settings'] as $old) {
            $this->assertStringNotContainsString('data-perm-key="' . $old . '"', $html,
                $old . ' belongs to the retired scheme and must not be offered as a control');
        }
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

    /**
     * The most destructive control on the page was the only unguarded one.
     *
     * Deleting an EMPTY group opens a confirmation dialog. Revoking every
     * permission from a group three administrators are in did not. The guard
     * rail was on the reversible action and absent from the irreversible one.
     */
    public function test_revoking_everything_asks_first(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('user_groups.create'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/data-cascade="off"[^>]*data-confirm="[^"]+"|data-confirm="[^"]+"[^>]*data-cascade="off"/',
            $html,
            'the "Off all" cascade must carry data-confirm, the same house convention Delete uses'
        );
    }

    /**
     * The picker announces itself, and says something worth hearing.
     *
     * Nothing on it had aria-live, so "Off all" removed every permission in
     * silence. And the visible counter reads "38 / 38", which is right for
     * the eye and useless spoken - so it is hidden from assistive technology
     * and a sentence is carried alongside it.
     */
    public function test_the_permission_count_is_announced_as_a_sentence(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('user_groups.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-permv2="counter-say"', $html);
        $this->assertMatchesRegularExpression('/role="status"[^>]*data-permv2="counter-say"/', $html,
            'the spoken counter has to be a live region or it announces nothing');
        $this->assertMatchesRegularExpression('/data-permv2="counter"[^>]*aria-hidden="true"/', $html,
            'the terse "n / n" must not be announced alongside the sentence');

        $js = File::get(resource_path('js/permission-pill.js'));
        $this->assertStringContainsString('counter-say', $js,
            'a live region nothing updates is a live region that never fires');
    }

    /**
     * THE GROUPING IS THE DESIGN'S BEST IDEA AND IT WAS SIGHTED-ONLY.
     *
     * Eight <section> elements with no accessible name produce no landmark
     * and no grouping, and the section titles are buttons rather than
     * headings, so the whole 38-row form exposed three headings. Someone
     * navigating by structure heard one flat run of pills with no domain
     * boundary anywhere in it, and the six channel sub-labels were plain
     * divs with no tie to the rows beneath them.
     */
    public function test_the_domain_sections_and_channel_subgroups_are_named(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('user_groups.create'))
            ->assertOk()
            ->getContent();

        preg_match_all('/<section class="permv2-group"([^>]*)>/', $html, $sections);
        $this->assertNotEmpty($sections[1], 'the picker must render its domain sections');

        foreach ($sections[1] as $attrs) {
            $this->assertStringContainsString('aria-labelledby', $attrs,
                'a section with no accessible name is not a landmark and not a group');
        }

        // And every id they point at has to exist, or the name resolves to
        // nothing and the assertion above is satisfied by a broken reference.
        preg_match_all('/<section class="permv2-group"[^>]*aria-labelledby="([^"]+)"/', $html, $refs);

        foreach ($refs[1] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $html,
                "the section names itself after #{$id}, which does not exist");
        }

        /* No subgroup assertion any more, and its absence is the point.
         *
         * Channels used to be nested inside a "Marketplace API" container,
         * which is why they needed a role="group" of their own to be heard.
         * The catalogue makes each channel a top-level area - Lazada, Shopee,
         * TikTok Shop - so the nesting that needed naming is gone rather than
         * unnamed. What matters is that every area IS named, asserted above. */
        $catalogue = $this->app->make(\App\Services\PermissionCatalogue::class);

        $this->assertCount(count($catalogue->grouped()), $sections[1],
            'every area the catalogue derives should render as its own named section');
    }

    /**
     * A control announces what it actually does.
     *
     * The preset panel declared aria-haspopup="menu", role="menu" and
     * role="menuitem" and implemented none of the contract: no arrow keys, no
     * focus move, and Escape left it open with focus stranded inside. This is
     * the same call components/ui/menu.blade.php already documents.
     */
    public function test_the_preset_panel_does_not_claim_to_be_a_menu(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('user_groups.create'))
            ->assertOk()
            ->getContent();

        $picker = substr($html, (int) strpos($html, 'perm-picker-v2'));

        $this->assertStringNotContainsString('role="menuitem"', $picker);
        $this->assertStringNotContainsString('aria-haspopup', $picker,
            'aria-haspopup defaults to "menu"; a group of buttons must not promise one');
        $this->assertMatchesRegularExpression('/data-permv2="preset-menu"[^>]*role="group"/', $picker);

        // Escape is the part worth keeping, because everything else
        // dismissible in this application closes on it.
        $js = File::get(resource_path('js/permission-pill.js'));
        $this->assertStringContainsString("ev.key === 'Escape'", $js);
    }

    /** The save says what moved instead of returning to an unchanged-looking list. */
    public function test_saving_reports_what_changed(): void
    {
        $group = $this->groupWith('Fulfilment', ['view_products']);
        $grant = Permission::where('key', 'view_orders')->value('id');

        $this->actingAs($this->admin())
            ->put(route('user_groups.update', $group->id), [
                'name' => 'Fulfilment',
                'permissions' => [$grant],
            ])
            ->assertRedirect(route('user_groups.index'))
            ->assertSessionHas('status');

        $this->assertStringContainsString('revoked', (string) session('status'));
    }

    /** And says nothing when nothing moved, because "0 granted, 0 revoked" is noise. */
    public function test_saving_without_changing_anything_says_nothing(): void
    {
        $group = $this->groupWith('Fulfilment', ['view_products']);
        $actor = $this->admin();

        /* Save once first. A child grant elevates its parent - documented in
         * applyChildParentElevation - so submitting only view_products ADDS
         * view_catalog, and that is a real change. The settled state after
         * one save is what a genuine no-op looks like. */
        $this->actingAs($actor)->put(route('user_groups.update', $group->id), [
            'name' => 'Fulfilment',
            'permissions' => [Permission::where('key', 'view_products')->value('id')],
        ])->assertRedirect();

        $settled = $group->fresh()->permissions()->pluck('permissions.id')->all();

        $this->actingAs($actor)
            ->put(route('user_groups.update', $group->id), [
                'name' => 'Fulfilment',
                'permissions' => $settled,
            ])
            ->assertRedirect()
            ->assertSessionMissing('status');
    }

    /**
     * A zero is quieter, not fainter.
     *
     * opacity: .45 on 11px text measured 1.85:1 light and 2.17:1 dark, the
     * dimmest text on the page and less than half the AA floor. Opacity could
     * not fix it: only 1.0 clears 4.5:1 in both themes.
     */
    public function test_the_empty_section_counters_are_not_faded_below_readable(): void
    {
        $css = File::get(resource_path('css/components/permission-pill.css'));
        $rules = preg_replace('#/\*.*?\*/#s', '', $css);

        $this->assertDoesNotMatchRegularExpression(
            '/\.permv2-stat\[data-zero="1"\]\s*\{[^}]*opacity/',
            $rules,
            'fading this text is what put it at 1.85:1; de-emphasise the pip instead'
        );
    }

    /**
     * THE PRUNE MUST NEVER OFFER TO DELETE THE PERMISSION SYSTEM.
     *
     * claimedPermissionKeys reads config, the label file and the manifests.
     * None of them knows about keys derived from the route table, so once the
     * catalogue existed this command reported every derived key as abandoned
     * and offered to remove the lot, grants included. It was caught by
     * running the report rather than reading the code, which is the only
     * reason it is a test and not an incident.
     */
    public function test_the_derived_catalogue_is_never_reported_as_abandoned(): void
    {
        $manager = $this->app->make(ExtensionManager::class);
        $catalogue = $this->app->make(\App\Services\PermissionCatalogue::class);

        $orphans = array_flip($manager->orphanedPermissionKeys());
        $claimed = [];

        foreach ($catalogue->keys() as $key) {
            if (isset($orphans[$key])) {
                $claimed[] = $key;
            }
        }

        $this->assertSame([], $claimed, sprintf(
            "These keys are derived from the routes and reconcile would delete them: %s\n\n"
            .'A prune that removes the live scheme takes every grant with it.',
            implode(', ', array_slice($claimed, 0, 8))
        ));
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
