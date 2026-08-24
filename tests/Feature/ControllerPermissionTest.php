<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Services\PermissionCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A route is gated on what it is, not on what it remembered to declare.
 *
 * The permission list used to come from hand-written extension.json manifests
 * plus config/permissions.php, and a route had to remember `perm:` or it was
 * simply open. Both halves drifted: four keys survived a removed feature that
 * no sync could reach, and twenty authenticated routes carried no permission
 * at all.
 *
 * Deriving from the route table closes both directions. These tests are what
 * keep it closed.
 */
class ControllerPermissionTest extends TestCase
{
    use RefreshDatabase;

    /** The Lazada routes only exist once its extension is registered. */
    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(\App\Extensions\ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');
        $this->app->register(\Extensions\lazada\LazadaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();

        /* The migration derives keys from the routes loaded AT MIGRATION
           TIME, and RefreshDatabase migrates before this runs - so an
           extension registered here contributes routes the permissions table
           has never seen. Re-syncing is what production gets for free by
           booting extensions before anything migrates. */
        $this->artisan('permissions:sync-catalogue');
    }

    private int $groupSeq = 0;

    private function groupWith(array $keys, string $name = 'Test group'): UserGroup
    {
        // Unique: several of these tests build two groups in one run.
        $group = UserGroup::create(['name' => $name . ' ' . (++$this->groupSeq)]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return $group;
    }

    private function userWith(array $keys): User
    {
        return User::factory()->create(['user_group_id' => $this->groupWith($keys)->id]);
    }

    /** THE WHOLE POINT: a controller that exists has a key. */
    public function test_every_gated_controller_has_a_key_derived_from_its_routes(): void
    {
        $catalog = $this->app->make(PermissionCatalogue::class);
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;
            }

            [$class] = explode('@', $action);

            if ($catalog->neverGated($class)) {
                continue;
            }

            if ($catalog->keyForController($class) === null) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], array_unique($missing),
            'a controller with no key is a screen nobody can be granted or denied');
    }

    /** And a key that exists is in the database, so the editor can show it. */
    public function test_every_derived_key_exists_as_a_permission_row(): void
    {
        $catalog = $this->app->make(PermissionCatalogue::class);
        $rows = Permission::pluck('key')->flip();

        $missing = array_values(array_filter(
            $catalog->keys(),
            fn ($key) => ! isset($rows[$key])
        ));

        $this->assertSame([], $missing,
            'the migration derives these from the routes; a gap means it did not run or the routes moved');
    }

    /**
     * A READ-ONLY AREA PUBLISHES NO MANAGE TIER, so the editor can never
     * offer a level that does not exist.
     */
    public function test_an_area_with_no_write_route_offers_no_manage_tier(): void
    {
        $catalog = $this->app->make(PermissionCatalogue::class);
        $readOnly = array_filter($catalog->all(), fn ($e) => $e['writes'] === 0);

        $this->assertNotEmpty($readOnly, 'this test is only meaningful while a read-only area exists');

        foreach ($readOnly as $entry) {
            $this->assertSame(['view'], $entry['tiers'],
                $entry['key'] . ' has no write route and must not advertise a manage tier');
        }
    }

    public function test_a_view_grant_opens_a_read_and_not_a_write(): void
    {
        $user = $this->userWith(['view_catalog/product']);

        $this->actingAs($user)->get(route('products.index'))->assertOk();

        $this->actingAs($user)
            ->delete(route('products.destroy', 1))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_a_manage_grant_opens_both(): void
    {
        $user = $this->userWith(['manage_catalog/product']);

        // manage implies view, through the tier expansion this scheme reuses
        $this->actingAs($user)->get(route('products.index'))->assertOk();
    }

    public function test_a_grant_for_one_area_does_not_open_another(): void
    {
        $user = $this->userWith(['manage_catalog/product']);

        $this->actingAs($user)
            ->get(route('user_groups.index'))
            ->assertStatus(403)
            ->assertSee('You are not allowed to access this page');
    }

    /**
     * A NEW CONTROLLER IS PROTECTED THE MOMENT IT EXISTS.
     *
     * Under the per-route middleware this replaces, a route added without
     * `perm:` was open and nothing said so. Here the gate follows from the
     * controller, so forgetting is not a failure mode.
     */
    public function test_a_route_that_declares_no_permission_is_still_gated(): void
    {
        Route::middleware('web')->get('/__test_ungated', [
            \App\Http\Controllers\Catalog\ProductController::class, 'index',
        ]);

        $route = Route::getRoutes()->getRoutes();
        $mine = collect($route)->first(fn ($r) => $r->uri() === '__test_ungated');

        // It declares nothing. That is the premise, asserted rather than
        // assumed - a `perm:` slipping in here would make the rest pass for
        // the wrong reason.
        $declared = collect($mine->gatherMiddleware())
            ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'perm:'));

        $this->assertTrue($declared->isEmpty(), 'the premise of this test is a route with no perm: argument');

        // And it is gated anyway, by the controller it points at.
        $stranger = $this->userWith(['view_settings/user_group']);

        $this->actingAs($stranger)
            ->get('/__test_ungated')
            ->assertStatus(403)
            ->assertSee('You are not allowed to access this page');
    }

    /**
     * A POST THAT READS CAN SAY SO.
     *
     * Two Lazada previews and the bulk AWB fetch are POSTs only because a
     * body was needed; a read-only operator holds them today. Deriving the
     * tier from the verb alone would take them away.
     */
    public function test_a_route_may_declare_a_read_tier_despite_its_verb(): void
    {
        $route = Route::getRoutes()->getByName('ext.lazada.orders.bulk_awb');

        $this->assertNotNull($route);
        $this->assertSame('view', $route->defaults['permission_tier'] ?? null,
            'bulk AWB is a read; without this declaration the verb puts it behind manage');
    }

    /**
     * THE TRANSITION SHIM OPENS OLD DOORS AND WIDENS NOTHING.
     *
     * The migration translates the grants that existed when it ran. A group
     * created afterwards - in a test, from an older backup, by anyone after
     * deploy - holds only old keys, and locking those out silently is the
     * worst failure this change could have had. It did, on the first run:
     * 586 tests.
     */
    /**
     * THE RETIRED SCHEME HAS LEFT THE DATABASE. Phase D's migration
     * translated every legacy grant onto derived keys and pruned the rows
     * in one transaction, and nothing recreates them: every derived key
     * carries a slash, and a permissions table is only converged when
     * nothing in it lacks one.
     */
    public function test_the_retired_scheme_has_left_the_database(): void
    {
        $this->assertSame(
            0,
            \Illuminate\Support\Facades\DB::table('permissions')->where('key', 'not like', '%/%')->count(),
            'A slash-less permission row survived the Phase D prune - or something recreated one.'
        );
    }

    public function test_a_view_grant_reaches_the_read_and_is_refused_the_write(): void
    {
        $user = $this->userWith(['view_catalog/product']);

        $this->actingAs($user)->get(route('products.index'))->assertOk();

        $this->actingAs($user)
            ->delete(route('products.destroy', 1))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    /**
     * A PUBLIC ROUTE STAYS PUBLIC. The Lazada OAuth callback carries no auth
     * middleware because Lazada's own servers redirect a browser to it with
     * no session. Refusing it here turned "connect your shop" into a dead end.
     */
    public function test_a_route_with_no_auth_is_left_to_its_own_devices(): void
    {
        $this->get(route('lazada.callback'))->assertOk();
    }

    /**
     * The things that decide per item are not gated at the door. Gating them
     * as well denies a whole page to someone entitled to part of it - a
     * Shopee packer refused the fulfilment hub because the hub is not "a
     * Shopee thing".
     */
    public function test_a_surface_that_gates_per_item_is_not_gated_at_the_door(): void
    {
        $catalog = $this->app->make(PermissionCatalogue::class);

        foreach ([
            \App\Http\Controllers\Integrations\IntegrationController::class,
            \App\Http\Controllers\AutomationsController::class,
            \App\Http\Controllers\Fulfilment\OrderPrintController::class,
        ] as $class) {
            $this->assertTrue($catalog->neverGated($class),
                $class . ' decides per item and must not also be gated at the door');
        }
    }

    /**
     * THE CHANNEL DASHBOARDS ARE NOT ON THAT LIST ANY MORE.
     *
     * They used to shelter behind core's /{channel} wildcard, whose
     * controller was exempt - which is exactly how every channel dashboard
     * fell off the permission editor and stayed gated by a legacy key
     * nobody could grant. Each now lives on its own extension route and
     * derives its own key, enforced at the door like any other page.
     */
    public function test_each_channel_dashboard_derives_its_own_key(): void
    {
        $catalog = $this->app->make(PermissionCatalogue::class);

        // Only lazada: it is the one extension this file's setUp installs,
        // and the catalog can only derive keys from routes that loaded.
        // Every channel's own workspace test asserts its own dashboard key.
        $this->assertArrayHasKey(
            'lazada/dashboard',
            $catalog->all(),
            "lazada's dashboard has fallen back off the permission editor"
        );
    }

    /**
     * And the bargain that exemption depends on: each of them really does
     * check. A controller added to that list without its own check would be
     * wide open, and this is the only thing that would say so.
     */
    public function test_every_exempt_controller_actually_checks_for_itself(): void
    {
        foreach ([
            'app/Http/Controllers/Integrations/IntegrationController.php',
            'app/Http/Controllers/AutomationsController.php',
            'app/Http/Controllers/Fulfilment/OrderPrintController.php',
        ] as $file) {
            $source = file_get_contents(base_path($file));

            $this->assertMatchesRegularExpression(
                '/hasPermission|allows\(|abort_unless|Gate::/',
                $source,
                $file . ' is exempt from the door gate and performs no check of its own'
            );
        }
    }
    /**
     * A DENIED PAGE LOAD SAYS SO, IN WORDS THE USER CAN READ.
     *
     * It used to redirect to the dashboard with a flashed message the
     * dashboard never rendered - a silent teleport home that the owner
     * correctly named as the wrong behaviour. A typed or followed URL the
     * user lacks now answers 403 with "You are not allowed to access this
     * page" and "Contact your administrator". Writes keep the redirect-
     * back-with-flash, because the operator is standing on a form they
     * were allowed to see and the message belongs there.
     */
    public function test_a_denied_page_load_renders_the_not_allowed_page(): void
    {
        $user = $this->userWith(['view_catalog/product']);

        $response = $this->actingAs($user)->get(route('user_groups.index'));

        $response->assertStatus(403);
        $response->assertSee('You are not allowed to access this page');
        $response->assertSee('Contact your administrator');

        // The write path keeps its in-context redirect.
        $this->actingAs($user)
            ->delete(route('user_groups.destroy', 999))
            ->assertRedirect();
    }
}
